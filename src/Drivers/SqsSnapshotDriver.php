<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use Aws\Sqs\SqsClient;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SqsQueueName;

final class SqsSnapshotDriver implements QueueSnapshotDriver
{
    private const string ATTR_DEPTH = 'ApproximateNumberOfMessages';

    private const string ATTR_IN_FLIGHT = 'ApproximateNumberOfMessagesNotVisible';

    private const string ATTR_DELAYED = 'ApproximateNumberOfMessagesDelayed';

    /** @var array<string, array<string, string>> Attribute cache keyed by resolved URL. */
    private array $attrCache = [];

    /**
     * `$prefix` / `$suffix` mirror the queue connection's own config keys
     * (`Illuminate\Queue\Connectors\SqsConnector`). Both default to empty so
     * a plain `sqs` connection keeps the historical behaviour: names are
     * resolved through `GetQueueUrl` and cached, never assembled locally.
     * Laravel Cloud's managed queues supply both, which lets the URL be built
     * without an API round-trip.
     */
    public function __construct(
        private readonly SqsClient $client,
        private readonly string $connectionName,
        private readonly string $prefix = '',
        private readonly string $suffix = '',
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

    /**
     * Keyed on the connection's *logical* queue name, so a snapshot entry
     * written as a URL or with the suffix already applied still lands on the
     * same key the listeners write from the worker side.
     */
    public function canonicalKey(string $queue): string
    {
        return CanonicalQueueKey::forConnection($queue, $this->connectionName);
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

        // The physical name (configured name + connection suffix) is what AWS
        // knows. With no suffix configured this is the input verbatim, so the
        // cache key below is unchanged for existing plain-SQS deployments.
        $physical = SqsQueueName::physical($input, $this->suffix);

        // A configured prefix IS the queue-URL base
        // (`https://sqs.{region}.amazonaws.com/{account}`), so the URL can be
        // assembled locally — same shape `SqsQueue::suffixQueue()` builds.
        // Saves the GetQueueUrl round-trip and its Redis cache entry.
        if ($this->prefix !== '') {
            return rtrim($this->prefix, '/') . '/' . $physical;
        }

        $cacheKey = KeyPrefix::make("url:{$this->connectionName}:{$physical}");
        $redis = $this->insightsRedis();

        $cached = $redis->command('get', [$cacheKey]);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $result = $this->client->getQueueUrl(['QueueName' => $physical]);
        $urlRaw = $result['QueueUrl'] ?? '';
        $url = is_string($urlRaw) ? $urlRaw : '';

        if ($url === '') {
            throw new RuntimeException("SQS GetQueueUrl returned an empty URL for queue [{$physical}].");
        }

        $redis->command('setex', [$cacheKey, 3600, $url]);

        return $url;
    }

    private function insightsRedis(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
