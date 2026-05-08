<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

final class HostId
{
    /**
     * `gethostname()` returns the kernel hostname — reliable on bare
     * metal, Docker (container id), k8s (pod name), ECS (task hostname),
     * supervisor / systemd. Falls back to 'unknown' if the call returns
     * `false` or an empty string (rare).
     */
    public static function resolve(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'unknown';
    }
}
