<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Scheduler\ScheduleContext;
use SanderMuller\QueueInsights\Support\CallSiteResolver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageClaim;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ListenerFailureReporter;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\PendingPayloadSnapshot;
use SanderMuller\QueueInsights\Support\RedisBatch;
use SanderMuller\QueueInsights\Support\SerializedCommandReader;
use Throwable;

/**
 * Records the enqueue timestamp for a job so the worker-side
 * RecordJobProcessing handler can compute wait_ms.
 *
 * Join key: `payload.uuid`, NOT `JobQueued::$id`. Per
 * specs/horizon-inspired-features.md §2.2, `$event->id` is the
 * driver-generated identifier (Redis stream id / DB row PK / SQS
 * message id), which is *not* the same identifier returned by
 * `$event->job->uuid()` later in `RecordJobProcessing`. Decoding the
 * payload and reading `payload.uuid` is the only portable join key.
 */
final class RecordJobQueued
{
    public function handle(JobQueued $event): void
    {
        try {
            $payloadRaw = $event->payload;
            if (! is_string($payloadRaw) || $payloadRaw === '') {
                return;
            }

            $payload = json_decode($payloadRaw, true);
            $uuid = is_array($payload) && isset($payload['uuid']) && is_string($payload['uuid'])
                ? $payload['uuid']
                : null;

            if ($uuid === null || $uuid === '') {
                // Payload-without-uuid path — drivers / Laravel versions that
                // don't stamp a uuid into the payload. The modal renders `—`
                // for that job's wait time.
                return;
            }

            // Every write below goes out in one pipeline; the only follow-up
            // round trip is the lineage write for a popped chain claim.
            $batch = new RedisBatch(Redis::connection(Config::string('redis_connection', 'default')));

            $batch->command('setex', [
                KeyPrefix::make("pushed:{$uuid}"),
                3600,
                (string) microtime(true),
            ]);

            // Canonicalise — see ConnectionAlias docblock.
            $connection = ConnectionAlias::canonical((string) $event->connectionName);
            $queueKey = CanonicalQueueKey::fromOrDefault((string) $event->queue, $connection);

            // Resolve the dispatch call site once (when call-site capture is
            // on) so it can be both written to the interim qi:initiator key
            // AND stamped onto the pending hash. Empty string when capture is
            // off or no application frame resolved (e.g. a chained 2nd+ link).
            $callSite = $this->resolveCallSite();

            $this->writePendingTracking($batch, $event, $uuid, $payload, $connection, $queueKey, $callSite);
            $this->writeBatchTracking($batch, $uuid, $payload, $connection);
            $claimKey = $this->chainClaimKey($payload, $connection, $queueKey);
            $lineage = new ChainLineageStore();
            $claimIndex = $claimKey !== null && $lineage->usesPrimaryConnection()
                ? $batch->command('rpop', [$claimKey])
                : null;
            $this->writeScheduleAttribution($batch, $uuid);

            $results = $batch->execute();

            ChainLineageStore::bestEffort('RecordJobQueued', static function () use ($claimIndex, $results, $claimKey, $lineage, $uuid): void {
                $parentUuid = $claimIndex !== null
                    ? $results[$claimIndex] ?? null
                    : ($claimKey !== null ? $lineage->popClaim($claimKey) : null);
                if (is_string($parentUuid) && $parentUuid !== '') {
                    $lineage->writeLineage($uuid, $parentUuid, Config::int('chain_lineage.lineage_ttl_seconds', 604800));
                }
            });

            $this->writeInitiatorTracking($uuid, $callSite);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobQueued failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'redis_connection' => Config::string('redis_connection', 'default'),
            ]);

            resolve(ListenerFailureReporter::class)->report('RecordJobQueued', $throwable);
        }
    }

    /**
     * Stamp the per-uuid hash + per-queue sorted set so the dashboard can
     * show individual pending and delayed jobs. Driver-agnostic — the data
     * lives entirely in our Redis namespace, so SQS works alongside Redis
     * and database queues.
     *
     * @param  array<array-key, mixed>|null  $payload  Decoded JobQueued payload.
     * @param  string  $callSite  Resolved dispatch `file:line`, '' when absent.
     */
    private function writePendingTracking(RedisBatch $batch, JobQueued $event, string $uuid, ?array $payload, string $connection, string $queueKey, string $callSite): void
    {
        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        $displayName = is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])
            ? $payload['displayName']
            : '';

        if ($displayName === '') {
            // No class to label the row with — skip rather than show blank entries
            // in the inspector.
            return;
        }

        $queuedAt = Date::now()
            ->getTimestamp();
        $availableAt = $this->resolveAvailableAt($event, $queuedAt);

        $hashKey = KeyPrefix::make("pending:{$uuid}");
        $zsetKey = KeyPrefix::make("pending-zset:{$connection}:{$queueKey}");

        $ttl = Config::int('pending.ttl_seconds', 86400);

        // One HSET per field rather than a multi-field call: Predis 2.x's
        // hset() helper is 3-arg, and the flat multi-field shape diverges
        // between phpredis 4 and 5. They share the pipeline round trip.
        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : null;
        $batchId = is_array($data) && isset($data['batchId']) && is_string($data['batchId']) && $data['batchId'] !== ''
            ? $data['batchId']
            : '';

        $fields = [
            'connection' => $connection,
            'queue' => $queueKey,
            'class' => $displayName,
            'queued_at' => (string) $queuedAt,
            'available_at' => (string) $availableAt,
        ];
        // Only store batch_id when the job is part of a batch — most jobs
        // are not, and the empty placeholder cost ~30 B per hash × every
        // pending row. PendingJobsReader::parseHash treats absent fields
        // the same as empty strings, so the read contract is unchanged.
        if ($batchId !== '') {
            $fields['batch_id'] = $batchId;
        }

        // Initiator origin — read off the dispatching request's live hidden
        // Context (set by SetInitiatorOrigin middleware / the CommandStarting
        // listener / RecordScheduledTaskStarting). Omitted-when-empty exactly
        // like batch_id; PendingJobsReader::parseHash treats absent the same
        // as empty so the read contract is unchanged.
        $origin = $this->resolveInitiatorOrigin();
        if ($origin !== '') {
            $fields['origin'] = $origin;
        }

        // Initiator call site — the resolved dispatch `file:line` (when
        // call-site capture is on and an application frame resolved).
        // Omitted-when-empty exactly like origin / batch_id above.
        if ($callSite !== '') {
            $fields['call_site'] = $callSite;
        }

        // Pending payload capture — gated on a per-surface `pending.capture.payloads`
        // mode (off by default, separate from the completed-stream `capture.payloads`
        // because the memory math is structurally different: pending = N rows × TTL
        // × queues, vs completed's bounded MAXLEN trim).
        foreach ($this->buildPendingPayloadFields($payload) as $field => $value) {
            $fields[$field] = $value;
        }

        foreach ($fields as $field => $value) {
            $batch->command('hset', [$hashKey, $field, $value]);
        }

        $batch->command('expire', [$hashKey, $ttl]);

        $batch->command('zadd', [$zsetKey, $availableAt, $uuid]);
        $batch->command('expire', [$zsetKey, $ttl]);

        // Score-based eviction: keep the $cap highest-scored entries, drop
        // the lowest available_at first. A no-op while under the cap.
        $cap = Config::int('pending.max_per_queue', 10000);
        $batch->command('zremrangebyrank', [$zsetKey, 0, -($cap + 1)]);
    }

    /**
     * Resolve `available_at` (unix seconds) from the JobQueued event's `delay`
     * field. Laravel's event docblock types `delay` as `int|null` — the queue
     * Build the `payload_*` field map to merge into the pending hash. Runs
     * the decoded queued payload through {@see PendingPayloadSnapshot},
     * which mirrors the completed-stream redaction + size-cap logic against
     * the array shape we have at JobQueued time (no `Job` wrapper). Returns
     * `[]` when capture is off — keeps the hot path slim.
     *
     * @param  array<array-key, mixed>|null  $payload
     * @return array<string, string>
     */
    private function buildPendingPayloadFields(?array $payload): array
    {
        $mode = Config::enum('pending.capture.payloads', CaptureMode::class, CaptureMode::Off);
        if (! $mode->writesPayloadFields()) {
            return [];
        }

        $redactKeys = array_values(array_filter(
            Config::array('capture.redact_keys'),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));

        $built = PendingPayloadSnapshot::build(
            $payload,
            $mode->value,
            $redactKeys,
            Config::int('capture.max_field_bytes', 2048),
            Config::int('pending.capture.max_payload_bytes', 4096),
            Config::bool('pending.capture.include_command_body', false),
        );

        $out = [];
        foreach ($built as $field => $value) {
            if ($value === null) {
                continue;
            }

            $out[$field] = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $out;
    }

    /**
     * Read the coarse initiator origin off hidden Laravel `Context`. Empty
     * string when initiator capture is off or no entry-point hook stamped
     * one (dispatched from tinker, a daemon, a custom route group, etc).
     */
    private function resolveInitiatorOrigin(): string
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_origin', true)) {
            return '';
        }

        $origin = Context::getHidden(Config::string('initiator.context_key', 'qi_origin'));

        return is_string($origin) ? $origin : '';
    }

    /**
     * Resolve the dispatch call site (`file:line`) via a bounded, filtered
     * `debug_backtrace()` walk. Empty string when initiator capture or
     * call-site capture is off, or when no application frame was found
     * within the depth cap (e.g. a chained 2nd+ link queued by the
     * worker's chain machinery — no application frame above it).
     */
    private function resolveCallSite(): string
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_call_site', false)) {
            return '';
        }

        return (new CallSiteResolver())->resolve(Config::int('initiator.call_site_max_depth', 30)) ?? '';
    }

    /**
     * Persist the resolved call site into the interim `qi:initiator:{uuid}`
     * hash so the worker (RecordJobProcessed) and the failed-job dashboard
     * view can read it — the call site is captured queue-side but consumed
     * elsewhere and cannot ride Laravel `Context`.
     *
     * Peer of `writePendingTracking` off `handle()` — gated ONLY on the
     * initiator switches, NOT on `pending.enabled` (see spec §4 placement
     * trap). No key is written at all under the default config
     * (`capture_call_site = false`) or when no application frame resolved.
     */
    private function writeInitiatorTracking(string $uuid, string $callSite): void
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_call_site', false)) {
            return;
        }

        if ($callSite === '') {
            return;
        }

        (new InitiatorStore())->write(
            $uuid,
            ['call_site' => $callSite],
            Config::int('initiator.ttl_seconds', 604800),
        );
    }

    /**
     * dispatcher coerces DateTimeInterface inputs to int seconds before
     * dispatching the event.
     */
    private function resolveAvailableAt(JobQueued $event, int $queuedAt): int
    {
        $delay = $event->delay;

        return is_int($delay) ? $queuedAt + $delay : $queuedAt;
    }

    /**
     * Stamp batch-tracking keys when the queued payload carries a `batchId`
     * field (set by Laravel's `Bus::batch([...])` flow on every Batchable
     * job). The batchId is plaintext at `data.batchId` — readable even on
     * `ShouldBeEncrypted` jobs whose `data.command` is encrypted.
     *
     * Five keys per batch:
     *   - `qi:batches:index`               aggregate sorted set of batchIds, score = first-seen ts
     *   - `qi:batches:index:{connection}`  per-connection sorted set, first-write-wins via Lua
     *   - `qi:batch:{id}:connection`       string pointer; arbiter for first-write-wins
     *   - `qi:batch:{id}:uuids`            list of uuids in the batch (RPUSH order)
     *   - `qi:batch:uuid:{uuid}`           reverse lookup uuid → batchId (per-row chip)
     *
     * @param  array<array-key, mixed>|null  $payload  Decoded JobQueued payload.
     */
    private function writeBatchTracking(RedisBatch $batch, string $uuid, ?array $payload, string $connectionName): void
    {
        if (! Config::bool('batches.enabled', true)) {
            return;
        }

        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : null;

        $batchId = is_array($data) && isset($data['batchId']) && is_string($data['batchId']) && $data['batchId'] !== ''
            ? $data['batchId']
            : null;

        if ($batchId === null) {
            return;
        }

        $now = Date::now()
            ->getTimestamp();
        $ttl = Config::int('batches.ttl_seconds', 604800);
        $cap = Config::int('batches.max_uuids_per_batch', 5000);

        $indexKey = KeyPrefix::make('batches:index');
        // ZADD NX preserves the first-write-wins score so the head's
        // created-at timestamp survives concurrent JobQueued events.
        // Routed via eval() to dodge phpredis-vs-Predis option-shape divergence
        // — same pattern as RecordJobProcessed::xaddApprox.
        $batch->eval(
            "return redis.call('ZADD', KEYS[1], 'NX', ARGV[1], ARGV[2])",
            1,
            $indexKey,
            (string) $now,
            $batchId,
        );
        // EXPIRE on the index would reset whole-key TTL on every ZADD, so
        // old batchIds would never age out. Score-based pruning is cheap
        // (logarithmic) and self-bounding on each enqueue.
        $batch->command('zremrangebyscore', [$indexKey, '-inf', (string) ($now - $ttl)]);

        // Per-connection roster — first-write-wins via Lua so concurrent
        // JobQueued events on different connections for the same batchId
        // can't both claim the batch. The pointer key qi:batch:{id}:connection
        // is the single arbiter; the per-connection ZADD only runs on the
        // winning caller. The same script also stamps the per-uuid
        // connection side-key for BatchScopeFilter (one round-trip vs a
        // separate SETEX). Skip when connectionName is empty — drivers
        // without it can't be dropped into a per-connection roster.
        if ($connectionName !== '') {
            $batch->eval(
                LuaScripts::batchClaimConnection(),
                3,
                KeyPrefix::make("batch:{$batchId}:connection"),
                KeyPrefix::make("batches:index:{$connectionName}"),
                KeyPrefix::make("batch-uuid-conn:{$uuid}"),
                $connectionName,
                (string) $ttl,
                (string) $now,
                $batchId,
                (string) ($now - $ttl),
            );
        }

        // Capped append. Bus::findBatch()->totalJobs stays the authoritative
        // count; this list only feeds the batch-detail view.
        $batch->eval(
            "if redis.call('LLEN', KEYS[1]) < tonumber(ARGV[2]) then redis.call('RPUSH', KEYS[1], ARGV[1]); redis.call('EXPIRE', KEYS[1], ARGV[3]) end return 1",
            1,
            KeyPrefix::make("batch:{$batchId}:uuids"),
            $uuid,
            (string) $cap,
            (string) $ttl,
        );

        $batch->command('setex', [
            KeyPrefix::make("batch:uuid:{$uuid}"),
            $ttl,
            $batchId,
        ]);
    }

    /**
     * Try to attribute this newly-queued job to a parent that pushed a claim
     * ticket while entering processing. Per Phase 0 finding: the child's
     * serialized payload doesn't reliably carry a `chainConnection` /
     * `chainQueue` signal under default `Bus::chain([...])->dispatch()` usage,
     * so we attempt RPOP unconditionally — root jobs and non-chain dispatches
     * miss harmlessly.
     *
     * The claim key is built from (connection, queue, displayName,
     * tail-class fingerprint). The "tail" comes from the child's own
     * `chained` property, which Laravel re-serializes after `array_shift`
     * — so the child carries the same tail the parent computed at push
     * time, and the keys collide deterministically.
     *
     * Returns the claim key to RPOP, or null when lineage is off or the
     * payload can't be fingerprinted.
     *
     * @param  array<array-key, mixed>|null  $payload
     */
    private function chainClaimKey(?array $payload, string $connection, string $queueKey): ?string
    {
        if (! Config::bool('chain_lineage.enabled', true)) {
            return null;
        }

        $command = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? ($payload['data']['command'] ?? null)
            : null;

        if (! is_string($command) || $command === '') {
            return null;
        }

        $extracted = SerializedCommandReader::extract($command);
        if ($extracted === null) {
            return null;
        }

        $childClass = $extracted['class'];
        if (! is_string($childClass) || $childClass === '') {
            return null;
        }

        $tailClasses = $this->extractTailClasses($extracted['properties']['chained'] ?? null);
        if ($tailClasses === null) {
            // Malformed chained entry — bail rather than collide on a
            // partially-parsed parent fingerprint.
            return null;
        }

        return ChainLineageClaim::key($connection, $queueKey, $childClass, $tailClasses);
    }

    /**
     * Stamp the active scheduled-task frame onto this queued job's
     * metadata so the dashboard can answer "which scheduled task
     * dispatched this job?". No-op when nothing is on the stack
     * (i.e. queued from an HTTP request, queue worker, tinker
     * session, etc.) or when the package's pending-tracking is off
     * (the per-uuid pending hash is the only stable join surface).
     *
     * Two writes per attribution:
     *   - `qi:pending:{uuid}` HSET schedule_task_key + schedule_run_id
     *   - `qi:sched:run-jobs:{runId}` ZADD (score=now, member=uuid)
     *     so `ScheduleReader::jobsDispatchedDuring($runId)` is a
     *     single ZRANGE.
     */
    private function writeScheduleAttribution(RedisBatch $batch, string $uuid): void
    {
        if (! Config::bool('scheduler.enabled', false)) {
            return;
        }

        if (! Config::bool('pending.enabled', true)) {
            return;
        }

        $frame = ScheduleContext::current();
        if ($frame === null) {
            return;
        }

        $hashKey = KeyPrefix::make("pending:{$uuid}");
        $batch->command('hset', [$hashKey, 'schedule_task_key', $frame['task_key']]);
        $batch->command('hset', [$hashKey, 'schedule_run_id', $frame['run_id']]);

        $jobsKey = KeyPrefix::make("sched:run-jobs:{$frame['run_id']}");
        $batch->command('zadd', [$jobsKey, Date::now()->getTimestamp(), $uuid]);
        // Cap the per-run job index so a fan-out task (e.g. an importer
        // queueing 100k jobs) can't grow the zset unbounded. Trim the
        // oldest by score; the dashboard surfaces the most-recent slice.
        $cap = Config::int('scheduler.retention.run_jobs_max', 5000);
        $batch->command('zremrangebyrank', [$jobsKey, 0, -($cap + 1)]);
        $batch->command('expire', [$jobsKey, Config::int('scheduler.retention.run_ttl_seconds', 604800)]);
    }

    /**
     * Decode the `chained` property (a list of pre-serialized job bodies)
     * down to a list of class names. Returns null on the first malformed
     * entry — fail closed so the read-side key never collides with a
     * partially-parsed parent fingerprint.
     *
     * @return list<string>|null
     */
    private function extractTailClasses(mixed $chained): ?array
    {
        if ($chained === null) {
            return [];
        }

        if (! is_array($chained)) {
            return null;
        }

        $classes = [];
        foreach ($chained as $entry) {
            if (! is_string($entry) || $entry === '') {
                return null;
            }

            $sub = SerializedCommandReader::extract($entry);
            if ($sub === null || ! is_string($sub['class']) || $sub['class'] === '') {
                return null;
            }

            $classes[] = $sub['class'];
        }

        return $classes;
    }
}
