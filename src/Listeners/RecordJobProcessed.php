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
            $this->writeStreams($redis, $event, $class, $connectionName, $queueKey, $durationMs, $isoNow);
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
    ): void {
        $baseFields = [
            'class' => $class,
            'connection' => $connectionName,
            'queue' => $queueKey,
            'duration_ms' => (string) ($durationMs ?? ''),
            'attempts' => (string) $event->job->attempts(),
            'processed_at' => $isoNow,
        ];

        if (Config::string('capture.payloads', 'off') !== 'off') {
            foreach ($this->sanitizer->sanitize($event) as $key => $value) {
                $baseFields['payload_' . $key] = $this->encodeStreamValue($value);
            }
        }

        $globalMax = Config::int('retention.completed_stream_max', 10000);
        $perClassMax = Config::int('retention.per_class_stream_max', 1000);

        $globalKey = KeyPrefix::make('completed');
        $perClassKey = KeyPrefix::make("completed:{$class}");

        $this->xaddApprox($redis, $globalKey, $globalMax, $baseFields);

        $perClassFields = $baseFields;
        unset($perClassFields['class']);

        $this->xaddApprox($redis, $perClassKey, $perClassMax, $perClassFields);
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
    private function xaddApprox(RedisConnection $redis, string $key, int $maxLen, array $fields): void
    {
        // phpredis and Predis expose different XADD signatures. Route through eval() so a
        // single code path works on both drivers without a PhpRedisConnection::xAdd fork.
        RedisEval::exec(
            $redis,
            "return redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', unpack(ARGV, 2))",
            1,
            $key,
            (string) $maxLen,
            ...$this->flattenFields($fields),
        );
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
