<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResolveJobClass
{
    public function from(JobContract $job, string $connection, string $queue): string
    {
        try {
            $name = (string) $job->resolveName();

            if ($name === '' || $this->isClosureName($name)) {
                return "Closure@{$connection}:{$queue}";
            }

            if ($this->looksEncrypted($job)) {
                return "Encrypted@{$connection}:{$queue}";
            }

            return $name;
        } catch (Throwable $throwable) {
            if ($this->looksEncrypted($job)) {
                return "Encrypted@{$connection}:{$queue}";
            }

            $this->logUnresolvedOncePerMinute($throwable);

            return 'Unresolved';
        }
    }

    private function isClosureName(string $name): bool
    {
        return $name === CallQueuedClosure::class
            || str_contains($name, 'CallQueuedClosure');
    }

    private function looksEncrypted(JobContract $job): bool
    {
        try {
            $payload = $job->payload();
        } catch (Throwable) {
            return false;
        }

        $data = $payload['data'] ?? [];
        $command = is_array($data) ? ($data['command'] ?? null) : null;

        if (! is_string($command) || $command === '') {
            return false;
        }

        // PHP-serialized object / array / custom-serialized strings begin with O:, C:, or a:.
        return ! (str_starts_with($command, 'O:') || str_starts_with($command, 'C:') || str_starts_with($command, 'a:'));
    }

    private function logUnresolvedOncePerMinute(Throwable $e): void
    {
        Cache::remember('queue-insights:unresolved-log-lock', 60, function () use ($e): bool {
            Log::debug('queue-insights: job class unresolved', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return true;
        });
    }
}
