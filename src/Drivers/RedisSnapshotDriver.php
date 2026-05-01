<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;

final readonly class RedisSnapshotDriver implements QueueSnapshotDriver
{
    public function __construct(
        private string $queueConnection,
    ) {}

    public function depth(string $queue): int
    {
        return $this->asInt($this->redis()->command('llen', ["queues:{$queue}"]));
    }

    public function inFlight(string $queue): int
    {
        return $this->asInt($this->redis()->command('zcard', ["queues:{$queue}:reserved"]));
    }

    public function delayed(string $queue): int
    {
        return $this->asInt($this->redis()->command('zcard', ["queues:{$queue}:delayed"]));
    }

    private function asInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    public function canonicalKey(string $queue): string
    {
        return CanonicalQueueKey::from($queue);
    }

    private function redis(): Connection
    {
        $redisConnection = config("queue.connections.{$this->queueConnection}.connection", 'default');

        return Redis::connection(is_string($redisConnection) ? $redisConnection : 'default');
    }
}
