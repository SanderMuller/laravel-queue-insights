<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Events\QueueDepthExceeded;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\RedisEval;
use Throwable;

final class QueueInsightsSnapshotCommand extends Command
{
    protected $signature = 'queue-insights:snapshot';

    protected $description = 'Capture live depth / in-flight / delayed counts for each configured queue.';

    public function __construct(
        private readonly QueueSnapshotDriverFactory $factory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $redis = $this->redis();

        foreach (Config::array('snapshots') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if (! is_string($queue)) {
                continue;
            }

            $this->snapshot($redis, $connection, $queue);
        }

        $this->pruneClasses($redis);

        return self::SUCCESS;
    }

    private function snapshot(RedisConnection $redis, string $connection, string $queueInput): void
    {
        $driver = null;
        $canonicalKey = null;

        try {
            $driver = $this->factory->make($connection);
            $canonicalKey = $driver->canonicalKey($queueInput);

            $depth = $driver->depth($queueInput);
            $inFlight = $driver->inFlight($queueInput);
            $delayed = $driver->delayed($queueInput);

            $now = Date::now()->getTimestamp();

            $this->writeMetric($redis, 'depth', $connection, $canonicalKey, $now, $depth);

            if ($inFlight !== null) {
                $this->writeMetric($redis, 'inflight', $connection, $canonicalKey, $now, $inFlight);
            }

            if ($delayed !== null) {
                $this->writeMetric($redis, 'delayed', $connection, $canonicalKey, $now, $delayed);
            }

            $redis->command('del', [KeyPrefix::make("snapshot:error:{$connection}:{$canonicalKey}")]);

            $this->maybeAlert($redis, $connection, $canonicalKey, $depth);
        } catch (Throwable $throwable) {
            $this->recordError($redis, $connection, $queueInput, $canonicalKey, $throwable);
        }

        unset($driver);
    }

    private function writeMetric(
        RedisConnection $redis,
        string $metric,
        string $connection,
        string $canonicalKey,
        int $now,
        int $value,
    ): void {
        $historyKey = KeyPrefix::make("{$metric}:{$connection}:{$canonicalKey}");
        $liveKey = KeyPrefix::make("live:{$metric}:{$connection}:{$canonicalKey}");

        $redis->command('zadd', [$historyKey, $now, (string) $now]);
        $redis->command('expire', [$historyKey, 172800]);
        $redis->command('zremrangebyscore', [$historyKey, 0, $now - 86400]);

        $redis->command('setex', [$liveKey, 90, (string) $value]);
    }

    private function recordError(
        RedisConnection $redis,
        string $connection,
        string $queueInput,
        ?string $canonicalKey,
        Throwable $e,
    ): void {
        Log::warning('queue-insights: snapshot failed', [
            'connection' => $connection,
            'queue' => $queueInput,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $errorKey = $canonicalKey ?? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $queueInput) ?? '_');

        try {
            $redis->command('setex', [
                KeyPrefix::make("snapshot:error:{$connection}:{$errorKey}"),
                600,
                $e->getMessage(),
            ]);
        } catch (Throwable) {
            // If the insights Redis itself is unreachable we log above and move on.
        }
    }

    private function maybeAlert(RedisConnection $redis, string $connection, string $canonicalKey, int $depth): void
    {
        if (! Config::bool('alerts.enabled', false)) {
            return;
        }

        $threshold = $this->thresholdFor($connection, $canonicalKey);
        if ($threshold === null || $depth < $threshold) {
            return;
        }

        $cooldownKey = KeyPrefix::make("alert:cooldown:{$connection}:{$canonicalKey}");
        $cooldownSeconds = Config::int('alerts.cooldown_seconds', 900);

        // SET NX EX semantics — first caller wins, everyone else hits the existing key.
        // Route through eval() because SET key val EX ttl NX has different positional/options
        // shapes on phpredis vs Predis; eval side-steps the divergence. Returns 1 on acquire.
        $acquired = RedisEval::exec(
            $redis,
            "if redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2], 'NX') then return 1 else return 0 end",
            1,
            $cooldownKey,
            (string) Date::now()->getTimestamp(),
            (string) $cooldownSeconds,
        );

        if ($acquired !== 1) {
            return;
        }

        event(new QueueDepthExceeded($connection, $canonicalKey, $depth, $threshold));
    }

    private function thresholdFor(string $connection, string $canonicalKey): ?int
    {
        foreach (Config::array('alerts.thresholds') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['connection'] ?? null) !== $connection) {
                continue;
            }

            $queue = $entry['queue'] ?? null;
            $depth = $entry['depth'] ?? null;
            if (! is_string($queue)) {
                continue;
            }

            if (! is_int($depth)) {
                continue;
            }

            // Match by either raw input or canonical form.
            if (CanonicalQueueKey::from($queue) === $canonicalKey) {
                return $depth;
            }
        }

        return null;
    }

    private function pruneClasses(RedisConnection $redis): void
    {
        try {
            $redis->command('zremrangebyscore', [
                KeyPrefix::make('classes'),
                0,
                Date::now()->getTimestamp() - 2592000,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: classes prune failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
