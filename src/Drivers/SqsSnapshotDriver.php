<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use Aws\Sqs\SqsClient;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;

final class SqsSnapshotDriver implements QueueSnapshotDriver
{
    private const string ATTR_DEPTH = 'ApproximateNumberOfMessages';

    private const string ATTR_IN_FLIGHT = 'ApproximateNumberOfMessagesNotVisible';

    private const string ATTR_DELAYED = 'ApproximateNumberOfMessagesDelayed';

    /** @var array<string, array<string, string>> Attribute cache keyed by resolved URL. */
    private array $attrCache = [];

    public function __construct(
        private readonly SqsClient $client,
        private readonly string $connectionName,
    ) {}

    public function depth(string $queue): int
    {
        return (int) ($this->attributes($queue)[self::ATTR_DEPTH] ?? 0);
    }

    public function inFlight(string $queue): ?int
    {
        $value = $this->attributes($queue)[self::ATTR_IN_FLIGHT] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function delayed(string $queue): ?int
    {
        $value = $this->attributes($queue)[self::ATTR_DELAYED] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function canonicalKey(string $queue): string
    {
        return CanonicalQueueKey::from($queue);
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $queue): array
    {
        $url = $this->resolveUrl($queue);

        if (isset($this->attrCache[$url])) {
            return $this->attrCache[$url];
        }

        $result = $this->client->getQueueAttributes([
            'QueueUrl' => $url,
            'AttributeNames' => [
                self::ATTR_DEPTH,
                self::ATTR_IN_FLIGHT,
                self::ATTR_DELAYED,
            ],
        ]);

        $attrs = $result['Attributes'] ?? [];
        $normalized = [];

        if (is_array($attrs)) {
            foreach ($attrs as $k => $v) {
                if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
                    continue;
                }

                $normalized[(string) $k] = (string) $v;
            }
        }

        return $this->attrCache[$url] = $normalized;
    }

    private function resolveUrl(string $input): string
    {
        if (preg_match('/^https?:\/\//i', $input) === 1) {
            return $input;
        }

        $cacheKey = KeyPrefix::make("url:{$this->connectionName}:{$input}");
        $redis = $this->insightsRedis();

        $cached = $redis->command('get', [$cacheKey]);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $result = $this->client->getQueueUrl(['QueueName' => $input]);
        $urlRaw = $result['QueueUrl'] ?? '';
        $url = is_string($urlRaw) ? $urlRaw : '';

        if ($url === '') {
            throw new RuntimeException("SQS GetQueueUrl returned an empty URL for queue [{$input}].");
        }

        $redis->command('setex', [$cacheKey, 3600, $url]);

        return $url;
    }

    private function insightsRedis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
