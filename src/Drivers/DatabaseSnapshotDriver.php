<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;

final readonly class DatabaseSnapshotDriver implements QueueSnapshotDriver
{
    public function __construct(
        private string $queueConnection,
    ) {}

    public function depth(string $queue): int
    {
        $retryAfter = $this->retryAfter();
        $now = $this->now();
        $threshold = $now - $retryAfter;

        return $this->query($queue)
            ->where(function (Builder $q) use ($now, $threshold): void {
                $q->where(function (Builder $q) use ($now): void {
                    $q->whereNull('reserved_at')->where('available_at', '<=', $now);
                })->orWhere(function (Builder $q) use ($threshold): void {
                    $q->whereNotNull('reserved_at')->where('reserved_at', '<=', $threshold);
                });
            })
            ->count();
    }

    public function inFlight(string $queue): int
    {
        $threshold = $this->now() - $this->retryAfter();

        return $this->query($queue)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>', $threshold)
            ->count();
    }

    public function delayed(string $queue): int
    {
        $now = $this->now();

        return $this->query($queue)
            ->whereNull('reserved_at')
            ->where('available_at', '>', $now)
            ->count();
    }

    public function canonicalKey(string $queue): string
    {
        return CanonicalQueueKey::from($queue);
    }

    private function query(string $queue): Builder
    {
        $dbConnection = config("queue.connections.{$this->queueConnection}.connection");
        $table = config("queue.connections.{$this->queueConnection}.table", 'jobs');

        return DB::connection(is_string($dbConnection) ? $dbConnection : null)
            ->table(is_string($table) ? $table : 'jobs')
            ->where('queue', $queue);
    }

    private function retryAfter(): int
    {
        $retry = config("queue.connections.{$this->queueConnection}.retry_after", 90);

        if (is_int($retry)) {
            return $retry;
        }

        return is_string($retry) && is_numeric($retry) ? (int) $retry : 90;
    }

    private function now(): int
    {
        return Date::now()->getTimestamp();
    }
}
