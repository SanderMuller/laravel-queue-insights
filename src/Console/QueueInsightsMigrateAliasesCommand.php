<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisEval;

/**
 * One-shot migration of pre-alias drift entries onto the canonical
 * `connection_aliases` target. Walks every `pending-zset:{from}:*` /
 * `inflight-zset:{from}:*` for each non-identity alias mapping, copies
 * members to the canonical zset preserving scores (ZADD NX so a worker's
 * canonical-side write since rollout isn't clobbered), DELs the source,
 * and rewrites the `connection` field on the corresponding
 * `pending:{uuid}` hashes.
 *
 * **NOT online-safe.** A worker that fires `RecordJobProcessed` mid-run
 * can ZREM the canonical-side entry that this command is in the process
 * of copying — resurrecting a phantom row. Operators MUST quiesce before
 * running: pause dispatch, drain workers, run, resume. The command refuses
 * unless `--force` is set, and prints the quiescence runbook before the
 * destructive pass.
 *
 * v0 scope: pending-zset + inflight-zset + `pending:{uuid}` `connection`
 * hash field. Other connection-keyed families (`classes:{c}`,
 * `completed:connection:{c}`, counters, history, snapshot:error, …) drain
 * via TTL or are overwritten on the next snapshot tick — operators who
 * need those migrated explicitly can file a follow-up.
 */
final class QueueInsightsMigrateAliasesCommand extends Command
{
    protected $signature = 'queue-insights:migrate-aliases
        {--force : Actually perform the migration. Default is dry-run.}';

    protected $description = 'Migrate pending / in-flight zset entries from pre-alias `{from}` keys onto the canonical `{to}` keys. REQUIRES operator-quiesced dispatch + drained workers.';

    public function handle(): int
    {
        $aliasMap = $this->loadAliasMap();
        if ($aliasMap === []) {
            $this->info('No non-identity `connection_aliases` configured — nothing to migrate.');

            return self::SUCCESS;
        }

        $this->printAliasMap($aliasMap);

        $force = (bool) $this->option('force');
        if (! $force) {
            $this->printQuiescenceRunbook();
        }

        $redis = $this->redis();
        $totals = ['pending_keys' => 0, 'inflight_keys' => 0, 'members_migrated' => 0, 'hash_fields_rewritten' => 0];

        foreach ($aliasMap as $from => $to) {
            $pending = $this->migrateZsetFamily($redis, 'pending-zset', $from, $to, $force);
            $totals['pending_keys'] += $pending['source_keys'];
            $totals['members_migrated'] += count($pending['migrated_members']);

            if ($force && $pending['migrated_members'] !== []) {
                $totals['hash_fields_rewritten'] += $this->rewriteHashConnectionField($redis, $pending['migrated_members'], $from, $to);
            }

            $inflight = $this->migrateZsetFamily($redis, 'inflight-zset', $from, $to, $force);
            $totals['inflight_keys'] += $inflight['source_keys'];
            $totals['members_migrated'] += count($inflight['migrated_members']);
        }

        $this->printSummary($totals, $force);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function loadAliasMap(): array
    {
        $out = [];
        foreach (Config::array('connection_aliases') as $from => $to) {
            if (! is_string($from)) {
                continue;
            }

            if (! is_string($to)) {
                continue;
            }

            if ($from === '') {
                continue;
            }

            if ($to === '') {
                continue;
            }

            if ($from === $to) {
                continue;
            }

            $out[$from] = $to;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $aliasMap
     */
    private function printAliasMap(array $aliasMap): void
    {
        $this->line('Migrating drift entries under the following alias map:');
        foreach ($aliasMap as $from => $to) {
            $this->line("  {$from} → {$to}");
        }
    }

    private function printQuiescenceRunbook(): void
    {
        $this->newLine();
        $this->warn('Dry-run. Re-run with --force to actually migrate.');
        $this->newLine();
        $this->line('Before --force, quiesce the workload:');
        $this->line('  1. Pause new dispatches on the pre-alias connection name(s).');
        $this->line('  2. Drain workers (queue:work --stop-when-empty, or horizon:terminate).');
        $this->line('  3. Confirm no producer / worker is still active against the affected store.');
        $this->line('  4. Run again with --force.');
        $this->line('  5. Resume dispatch + workers.');
        $this->newLine();
        $this->line('A running worker firing RecordJobProcessed mid-migration can resurrect rows.');
    }

    /**
     * @return array{source_keys: int, migrated_members: list<string>}
     */
    private function migrateZsetFamily(RedisConnection $redis, string $prefix, string $from, string $to, bool $force): array
    {
        $sourceKeys = $this->scanKeys($redis, KeyPrefix::make("{$prefix}:{$from}:") . '*');
        $migrated = [];

        foreach ($sourceKeys as $sourceKey) {
            $members = $this->zRangeWithScores($redis, $sourceKey);
            if ($members === []) {
                continue;
            }

            $targetKey = $this->translateKey($sourceKey, $prefix, $from, $to);

            $this->line(sprintf('  %s → %s (%d %s)', $sourceKey, $targetKey, count($members), count($members) === 1 ? 'member' : 'members'));

            if (! $force) {
                continue;
            }

            // ZADD with NX so a canonical-side write that happened
            // post-rollout isn't clobbered by the orphan score from the
            // pre-alias side. Routed via eval() to dodge phpredis-vs-Predis
            // option-shape divergence.
            foreach ($members as $member => $score) {
                RedisEval::exec(
                    $redis,
                    "return redis.call('ZADD', KEYS[1], 'NX', ARGV[1], ARGV[2])",
                    1,
                    $targetKey,
                    (string) $score,
                    $member,
                );
                $migrated[] = $member;
            }

            $redis->command('del', [$sourceKey]);
        }

        return ['source_keys' => count($sourceKeys), 'migrated_members' => $migrated];
    }

    /**
     * Enumerate keys matching `pattern` across the package's Redis
     * namespace. Uses `KEYS` rather than `SCAN`: the command runs once,
     * under operator-declared quiescence (per the runbook), against a
     * pattern bounded to a single alias-from prefix. Cost is O(N) over the
     * package's key set — acceptable for a one-shot migration where
     * concurrent producers/workers are paused. The SCAN cursor-management
     * shape diverges enough between phpredis (by-ref cursor + `false`
     * sentinel) and predis (options-array + `'0'` sentinel) that the
     * portability cost outweighed the production-time-streaming win.
     *
     * @return list<string>
     */
    private function scanKeys(RedisConnection $redis, string $pattern): array
    {
        $reply = $redis->command('keys', [$pattern]);
        if (! is_array($reply)) {
            return [];
        }

        // Laravel's Redis wrapper auto-prefixes writes
        // (`database.redis.options.prefix`, default `laravel-database-`) but
        // KEYS returns the underlying full key including that prefix.
        // Subsequent ZRANGE / ZADD / DEL calls re-apply the prefix, so we
        // strip it here before handing keys to the migration loop.
        $clientPrefix = $this->clientPrefix($redis);

        $out = [];
        foreach ($reply as $key) {
            if (! is_string($key)) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            if ($clientPrefix !== '' && str_starts_with($key, $clientPrefix)) {
                $key = substr($key, strlen($clientPrefix));
            }

            $out[] = $key;
        }

        return $out;
    }

    /**
     * Resolve the Redis-client prefix (`database.redis.options.prefix`,
     * default `laravel-database-`). Laravel's Redis manager applies this
     * automatically on writes; we need to strip it after `KEYS` because
     * the reply carries the underlying full key and a subsequent ZRANGE
     * would re-apply the prefix, missing the key.
     *
     * Reads from config rather than introspecting the client so the lookup
     * is driver-agnostic and statically typed.
     */
    private function clientPrefix(RedisConnection $redis): string
    {
        $name = $redis->getName() ?? Config::string('redis_connection', 'default');
        $prefix = config("database.redis.{$name}.options.prefix", config('database.redis.options.prefix'));

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * @return array<string, float>
     */
    private function zRangeWithScores(RedisConnection $redis, string $key): array
    {
        $reply = $redis->command('zrange', [$key, 0, -1, ['WITHSCORES' => true]]);
        if (! is_array($reply)) {
            return [];
        }

        // phpredis returns assoc [member => score]; predis returns a flat
        // [member, score, member, score, …] list. Normalise.
        if ($reply !== [] && array_is_list($reply)) {
            $out = [];
            for ($i = 0, $n = count($reply); $i + 1 < $n; $i += 2) {
                $member = $reply[$i];
                $score = $reply[$i + 1];
                if (is_string($member) && is_numeric($score)) {
                    $out[$member] = (float) $score;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($reply as $member => $score) {
            if (is_string($member) && is_numeric($score)) {
                $out[$member] = (float) $score;
            }
        }

        return $out;
    }

    /**
     * Translate a source key like `{packagePrefix}{prefix}:{from}:{queue}`
     * to the canonical-side equivalent by stripping the known source-prefix
     * and rebuilding via `KeyPrefix::make` with the target connection.
     */
    private function translateKey(string $sourceKey, string $prefix, string $from, string $to): string
    {
        $sourcePrefix = KeyPrefix::make("{$prefix}:{$from}:");
        $queue = str_starts_with($sourceKey, $sourcePrefix)
            ? substr($sourceKey, strlen($sourcePrefix))
            : $sourceKey; // unreachable under the SCAN MATCH pattern; pass through.

        return KeyPrefix::make("{$prefix}:{$to}:{$queue}");
    }

    /**
     * Rewrite `pending:{uuid}.connection` from the pre-alias name to the
     * canonical alias. Only updates rows whose stored value still matches
     * the pre-alias name — defensive against operators re-running the
     * command after a partial rollout.
     *
     * Per-uuid HGET+HSET round-trips are acceptable here: the command runs
     * under operator-declared quiescence (per the runbook), and the
     * rewrite touches at most one hash per migrated zset member.
     *
     * @param  list<string>  $uuids
     */
    private function rewriteHashConnectionField(RedisConnection $redis, array $uuids, string $from, string $to): int
    {
        $updated = 0;
        foreach ($uuids as $uuid) {
            $hashKey = KeyPrefix::make("pending:{$uuid}");
            $current = $redis->command('hget', [$hashKey, 'connection']);
            if ($current === $from) {
                $redis->command('hset', [$hashKey, 'connection', $to]);
                ++$updated;
            }
        }

        return $updated;
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function printSummary(array $totals, bool $force): void
    {
        $this->newLine();
        if ($force) {
            $this->info(sprintf(
                'Migrated %d zset %s (%d pending + %d inflight). Rewrote %d pending:{uuid}.connection %s.',
                $totals['pending_keys'] + $totals['inflight_keys'],
                ($totals['pending_keys'] + $totals['inflight_keys']) === 1 ? 'source key' : 'source keys',
                $totals['pending_keys'],
                $totals['inflight_keys'],
                $totals['hash_fields_rewritten'],
                $totals['hash_fields_rewritten'] === 1 ? 'field' : 'fields',
            ));

            return;
        }

        $this->line(sprintf(
            'Dry-run summary: would migrate %d zset source keys (%d pending + %d inflight).',
            $totals['pending_keys'] + $totals['inflight_keys'],
            $totals['pending_keys'],
            $totals['inflight_keys'],
        ));
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
