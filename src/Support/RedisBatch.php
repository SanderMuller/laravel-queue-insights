<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;
use RuntimeException;

/**
 * Collects Redis commands and sends them in one round trip. The job
 * listeners fire on every job, so one pipeline per listener phase instead of
 * one round trip per command is most of their cost on a remote Redis.
 *
 * Parameters take the same driver-native shape `Connection::command()`
 * takes, so a call moves into a batch unchanged. `execute()` routes through
 * `RedisPipeline`, which falls back to per-command execution on Redis
 * Cluster.
 *
 * phpredis does not throw on an error reply inside a pipeline; it returns
 * `false` for that slot and sets the client's last error. `execute()` turns
 * that into an exception so the listener's catch path still logs and reports
 * it, matching Predis.
 *
 * @internal
 */
final class RedisBatch
{
    /** @var list<array{string, list<mixed>}> */
    private array $commands = [];

    public function __construct(private readonly Connection $redis) {}

    /**
     * Queue a command; returns its index in the `execute()` result list.
     *
     * @param  list<mixed>  $parameters
     */
    public function command(string $method, array $parameters): int
    {
        $this->commands[] = [$method, $parameters];

        return count($this->commands) - 1;
    }

    /**
     * Queue an EVAL in the shape the active driver expects (see `RedisEval`).
     */
    public function eval(string $script, int $numKeys, string ...$keysAndArgs): int
    {
        return $this->command('eval', $this->redis instanceof PhpRedisConnection
            ? [$script, array_values($keysAndArgs), $numKeys]
            : [$script, $numKeys, ...array_values($keysAndArgs)]);
    }

    /**
     * Send every queued command and return the replies in queue order.
     *
     * @return list<mixed>
     */
    public function execute(): array
    {
        $commands = $this->commands;
        $this->commands = [];

        if ($commands === []) {
            return [];
        }

        $client = $this->phpRedisClient();
        $client?->clearLastError();

        $results = count($commands) === 1
            ? [$this->redis->command($commands[0][0], $commands[0][1])]
            : RedisPipeline::run($this->redis, static function (mixed $pipe) use ($commands): void {
                foreach ($commands as [$method, $parameters]) {
                    $call = [$pipe, $method];
                    if (! is_callable($call)) {
                        throw new RuntimeException("queue-insights: Redis client cannot pipeline {$method}");
                    }

                    $call(...$parameters);
                }
            });

        $error = $client?->getLastError();
        if (is_string($error) && $error !== '') {
            throw new RuntimeException('queue-insights: Redis command failed: ' . $error);
        }

        return $results;
    }

    private function phpRedisClient(): ?Redis
    {
        if (! $this->redis instanceof PhpRedisConnection || $this->redis instanceof PhpRedisClusterConnection) {
            return null;
        }

        $client = $this->redis->client();

        return $client instanceof Redis ? $client : null;
    }
}
