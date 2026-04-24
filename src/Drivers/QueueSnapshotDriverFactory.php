<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use Aws\Sqs\SqsClient;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SanderMuller\QueueInsights\Contracts\QueueSnapshotDriver;

final class QueueSnapshotDriverFactory
{
    public function make(string $connection): QueueSnapshotDriver
    {
        $override = config("queue-insights.driver_overrides.{$connection}");

        if ($override !== null) {
            return $this->fromOverride($connection, $override);
        }

        $connectionConfig = config("queue.connections.{$connection}");

        if (! is_array($connectionConfig)) {
            return $this->unknownDriver($connection, '(missing)');
        }

        $driver = $connectionConfig['driver'] ?? '';

        return $this->fromDriverName($connection, is_string($driver) ? $driver : '');
    }

    private function fromOverride(string $connection, mixed $override): QueueSnapshotDriver
    {
        if ($override instanceof QueueSnapshotDriver) {
            return $override;
        }

        if ($override instanceof Closure) {
            $result = $override($connection);

            if (! $result instanceof QueueSnapshotDriver) {
                throw new RuntimeException("queue-insights.driver_overrides.{$connection} closure must return a QueueSnapshotDriver.");
            }

            return $result;
        }

        if (is_string($override)) {
            // Built-in driver names take precedence over class-string lookup —
            // otherwise a host-app class named `Redis` (phpredis extension) or similar
            // would collide with the driver-name keyword.
            if (in_array($override, ['sqs', 'redis', 'database', 'null', 'sync', ''], true)) {
                return $this->fromDriverName($connection, $override);
            }

            if (class_exists($override)) {
                $instance = resolve($override);

                if ($instance instanceof QueueSnapshotDriver) {
                    return $instance;
                }

                throw new RuntimeException("queue-insights.driver_overrides.{$connection} class [{$override}] must implement QueueSnapshotDriver.");
            }

            return $this->fromDriverName($connection, $override);
        }

        throw new RuntimeException("queue-insights.driver_overrides.{$connection} must be a string, Closure, or QueueSnapshotDriver instance.");
    }

    private function fromDriverName(string $connection, string $driver): QueueSnapshotDriver
    {
        return match ($driver) {
            'sqs' => $this->makeSqs($connection),
            'redis' => new RedisSnapshotDriver($connection),
            'database' => new DatabaseSnapshotDriver($connection),
            'null', 'sync', '' => new NullSnapshotDriver(),
            default => $this->unknownDriver($connection, $driver),
        };
    }

    private function unknownDriver(string $connection, string $driver): NullSnapshotDriver
    {
        Log::warning('queue-insights: unknown queue driver; using NullSnapshotDriver', [
            'connection' => $connection,
            'driver' => $driver,
        ]);

        return new NullSnapshotDriver();
    }

    private function makeSqs(string $connection): SqsSnapshotDriver
    {
        $cfgRaw = config("queue.connections.{$connection}", []);
        $cfg = is_array($cfgRaw) ? $cfgRaw : [];

        $credentials = array_filter([
            'key' => $cfg['key'] ?? null,
            'secret' => $cfg['secret'] ?? null,
            'token' => $cfg['token'] ?? null,
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        $region = $cfg['region'] ?? 'us-east-1';

        $clientConfig = [
            'region' => is_string($region) ? $region : 'us-east-1',
            'version' => 'latest',
        ];

        if ($credentials !== []) {
            $clientConfig['credentials'] = $credentials;
        }

        return new SqsSnapshotDriver(new SqsClient($clientConfig), $connection);
    }
}
