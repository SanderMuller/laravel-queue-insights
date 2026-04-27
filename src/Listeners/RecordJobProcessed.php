<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use SanderMuller\QueueInsights\Support\SerializedCommandReader;
use Throwable;

final readonly class RecordJobProcessed
{
    public function __construct(
        private ResolveJobClass $resolveJobClass,
        private PayloadSanitizer $sanitizer,
    ) {}

    public function handle(JobProcessed $event): void
    {
        try {
            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            $connectionName = (string) $event->connectionName;
            $queueRaw = (string) $event->job->getQueue();
            $queueKey = CanonicalQueueKey::from($queueRaw === '' ? 'default' : $queueRaw);

            $class = $this->resolveJobClass->from($event->job, $connectionName, $queueKey);

            $now = CarbonImmutable::now('UTC');
            $nowTs = $now->getTimestamp();
            $bucket = $now->format('YmdH');
            $isoNow = $now->toIso8601String();

            $durationMs = $this->readAndConsumeStart($redis, $event->job->uuid());

            // Processed counter + bucket expiry
            $processedKey = KeyPrefix::make("processed:{$class}:{$bucket}");
            $redis->command('incr', [$processedKey]);
            $redis->command('expireat', [$processedKey, $this->bucketStart($bucket) + (7 * 86400)]);

            // Duration hash (processed jobs only)
            if ($durationMs !== null) {
                $durationKey = KeyPrefix::make("duration:{$class}");
                $redis->command('hincrby', [$durationKey, 'count', 1]);
                $redis->command('hincrbyfloat', [$durationKey, 'sum_ms', (float) $durationMs]);
                RedisEval::exec($redis, LuaScripts::updateMaxDuration(), 1, $durationKey, (string) $durationMs);
                $redis->command('expire', [$durationKey, 2592000]);

                // p95 sample window: last 500 durations per class.
                $sampleKey = KeyPrefix::make("duration:samples:{$class}");
                $redis->command('rpush', [$sampleKey, (string) $durationMs]);
                $redis->command('ltrim', [$sampleKey, -500, -1]);
                $redis->command('expire', [$sampleKey, 2592000]);
            }

            // Last run
            $redis->command('setex', [KeyPrefix::make("last_run:{$class}"), 2592000, $isoNow]);

            // Classes ZSET
            $redis->command('zadd', [KeyPrefix::make('classes'), $nowTs, $class]);

            // Streams
            $globalStreamId = $this->writeStreams($redis, $event, $class, $connectionName, $queueKey, $durationMs, $isoNow);

            // Batch tracking — index uuid → completed-stream entry id so the
            // batch-detail view can resolve a uuid to the existing completed
            // modal flow (which opens by stream id).
            $uuidForBatch = $event->job->uuid();
            if (
                $globalStreamId !== null
                && $uuidForBatch !== null
                && $uuidForBatch !== ''
                && Config::bool('batches.enabled', true)
            ) {
                $redis->command('setex', [
                    KeyPrefix::make("uuid-completed:{$uuidForBatch}"),
                    Config::int('batches.ttl_seconds', 604800),
                    $globalStreamId,
                ]);
            }

            // Belt-and-suspenders pending-tracking cleanup. RecordJobProcessing
            // already cleared on the pending → in-flight transition; this is
            // here for the rare case where that listener was missed (worker
            // crash between event dispatch and listener execution).
            $uuidForCleanup = $event->job->uuid();
            if ($uuidForCleanup !== null && $uuidForCleanup !== '' && Config::bool('pending.enabled', true)) {
                $redis->command('del', [KeyPrefix::make("pending:{$uuidForCleanup}")]);
                $redis->command('zrem', [KeyPrefix::make("pending-zset:{$connectionName}:{$queueKey}"), $uuidForCleanup]);
                // In-flight zset entry was added by RecordJobProcessing; drop
                // it now so the dashboard's In-flight group only ever shows
                // jobs that are actually running.
                $redis->command('zrem', [KeyPrefix::make("inflight-zset:{$connectionName}:{$queueKey}"), $uuidForCleanup]);
            }
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobProcessed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function readAndConsumeStart(RedisConnection $redis, ?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $key = KeyPrefix::make("start:{$uuid}");
        $start = $redis->command('get', [$key]);

        if (! is_numeric($start)) {
            return null;
        }

        $redis->command('del', [$key]);

        $ms = (int) round((microtime(true) - (float) $start) * 1000);

        return max($ms, 0);
    }

    private function bucketStart(string $bucket): int
    {
        $dt = CarbonImmutable::createFromFormat('YmdH', $bucket, 'UTC');

        if (! $dt instanceof CarbonImmutable) {
            return CarbonImmutable::now('UTC')->startOfHour()->getTimestamp();
        }

        return $dt->startOfHour()->getTimestamp();
    }

    private function writeStreams(
        RedisConnection $redis,
        JobProcessed $event,
        string $class,
        string $connectionName,
        string $queueKey,
        ?int $durationMs,
        string $isoNow,
    ): ?string {
        $baseFields = [
            'class' => $class,
            'connection' => $connectionName,
            'queue' => $queueKey,
            'duration_ms' => (string) ($durationMs ?? ''),
            'attempts' => (string) $event->job->attempts(),
            'processed_at' => $isoNow,
            // uuid lets enrichCompletedRows reverse-route a row to its batch
            // via `qi:batch:uuid:{uuid}` without needing payload capture on.
            'uuid' => (string) ($event->job->uuid() ?? ''),
        ];

        // Forward chain context — JSON-encoded list of every chained job's
        // class + per-link connection/queue, so the modal can offer a click-
        // through detail view without needing payload capture on. Typical
        // size: ~80-300 bytes for a 1-5 link chain.
        $chainJson = $this->encodedChain($event);
        if ($chainJson !== null) {
            $baseFields['chain'] = $chainJson;
        }

        if (Config::string('capture.payloads', 'off') !== 'off') {
            foreach ($this->sanitizer->sanitize($event) as $key => $value) {
                $baseFields['payload_' . $key] = $this->encodeStreamValue($value);
            }
        }

        $globalMax = Config::int('retention.completed_stream_max', 10000);
        $perClassMax = Config::int('retention.per_class_stream_max', 1000);

        $globalKey = KeyPrefix::make('completed');
        $perClassKey = KeyPrefix::make("completed:{$class}");

        $globalStreamId = $this->xaddApprox($redis, $globalKey, $globalMax, $baseFields);

        $perClassFields = $baseFields;
        unset($perClassFields['class']);

        $this->xaddApprox($redis, $perClassKey, $perClassMax, $perClassFields);

        return $globalStreamId;
    }

    private function encodedChain(JobProcessed $event): ?string
    {
        $payload = $event->job->payload();
        $data = $payload['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        $chain = SerializedCommandReader::extractChainContext($command);
        if ($chain === null) {
            return null;
        }

        // Persist class/connection/queue only — `properties` per chained job
        // would bloat the stream entry (typical user-data blob is far larger
        // than the routing summary), can carry __PHP_Incomplete_Class refs
        // that don't round-trip cleanly through JSON, and may include PII
        // that the captured-stream retention window outlives. The failed-job
        // modal re-extracts properties at render time from the full
        // serialized payload — that path keeps them.
        $slim = array_map(static fn (array $job): array => [
            'class' => $job['class'],
            'connection' => $job['connection'],
            'queue' => $job['queue'],
        ], $chain['jobs']);

        $encoded = json_encode($slim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    private function encodeStreamValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function xaddApprox(RedisConnection $redis, string $key, int $maxLen, array $fields): ?string
    {
        // phpredis and Predis expose different XADD signatures. Route through eval() so a
        // single code path works on both drivers without a PhpRedisConnection::xAdd fork.
        $result = RedisEval::exec(
            $redis,
            "return redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', unpack(ARGV, 2))",
            1,
            $key,
            (string) $maxLen,
            ...$this->flattenFields($fields),
        );

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private function flattenFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $out[] = $k;
            $out[] = $v;
        }

        return $out;
    }
}
