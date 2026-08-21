<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

/**
 * The `queue.connections.cloud` array a Laravel Cloud instance runs with.
 *
 * Keys and nesting were read off a live deployment; the values here are
 * invented — the real `prefix` and `suffix` carry an AWS account id, so they
 * are deliberately not fixtures. `queues`, `agent`, and `overflow` are kept
 * because the real config has them: they are Cloud's own, absent from a plain
 * SQS connection, and the package has to step past them rather than trip on
 * them.
 *
 * `Illuminate\Foundation\Cloud::configureManagedQueues()` is what materialises
 * this; nothing in the app's own `config/queue.php` declares it.
 */
final class CloudQueueConfig
{
    public const string PREFIX = 'https://sqs.eu-west-1.amazonaws.com/123456789012';

    public const string SUFFIX = '-abc123';

    /**
     * @param  array<string, mixed>  $overrides  Replaces top-level keys wholesale.
     * @return array<string, mixed>
     */
    public static function make(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'cloud',
            'queue' => 'default',
            'queues' => ['default', 'stats', 'mail'],
            'connection' => self::connection(),
            'agent' => ['enabled' => true],
        ], $overrides);
    }

    /**
     * The nested real connection — a complete SQS connection config in its own
     * right, which is what the snapshot driver unwraps to.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function connection(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'sqs',
            'queue' => 'default',
            'prefix' => self::PREFIX,
            'suffix' => self::SUFFIX,
            'region' => 'eu-west-1',
            'credentials' => 'ecs',
            'after_commit' => false,
            'overflow' => [
                'enabled' => false,
                'store' => null,
                'always' => false,
                'delete_after_processing' => true,
            ],
        ], $overrides);
    }

    /**
     * The queue URL a worker reports for a managed queue — what
     * `SqsJob::getQueue()` returns, i.e. the physical (suffixed) name.
     */
    public static function url(string $logicalQueue): string
    {
        $physical = str_ends_with($logicalQueue, '.fifo')
            ? substr($logicalQueue, 0, -5) . self::SUFFIX . '.fifo'
            : $logicalQueue . self::SUFFIX;

        return self::PREFIX . '/' . $physical;
    }
}
