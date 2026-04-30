<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Drivers\QueueSnapshotDriverFactory;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

final class QueueInsightsSnapshotCommand extends Command
{
    protected $signature = 'queue-insights:snapshot';

    protected $description = 'Capture live depth / in-flight / delayed counts for each configured queue.';

    public function __construct(
        private readonly QueueSnapshotDriverFactory $factory,
        private readonly IssueDispatcher $dispatcher,
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

        $this->dispatcher->dispatchClassScoped();
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

            $this->writeDepthSample($redis, $connection, $canonicalKey, $now, $depth);

            $this->dispatcher->dispatchForSnapshot($connection, $canonicalKey, $depth);
        } catch (Throwable $throwable) {
            $errorCanonicalKey = $this->recordError($redis, $connection, $queueInput, $canonicalKey, $throwable);
            $this->dispatcher->dispatchSnapshotError($connection, $errorCanonicalKey);
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

    /**
     * Capped (ts, depth) sample series powering the `backlog_growing`
     * detector. Stored as a ZSET so timestamp ordering is free, and the
     * encoded member `"{ts}:{depth}"` keeps both values addressable
     * without a second key family. Cap to the most-recent 30 samples
     * (~30 minutes at the default cadence) and TTL the whole key at 2 h
     * so an idle queue's series ages out automatically.
     *
     * Always written — the cost is three commands per snapshot per
     * queue. Toggling `alerts.rules.backlog_growing.enabled` without a
     * warm-up window is the operator-friendly default.
     */
    private function writeDepthSample(
        RedisConnection $redis,
        string $connection,
        string $canonicalKey,
        int $now,
        int $depth,
    ): void {
        $key = KeyPrefix::make("samples:depth:{$connection}:{$canonicalKey}");

        $redis->command('zadd', [$key, $now, "{$now}:{$depth}"]);
        $redis->command('zremrangebyrank', [$key, 0, -31]);
        $redis->command('expire', [$key, 7200]);
    }

    private function recordError(
        RedisConnection $redis,
        string $connection,
        string $queueInput,
        ?string $canonicalKey,
        Throwable $e,
    ): string {
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

        return $errorKey;
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
