<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Drivers;

use Aws\Credentials\CredentialProvider;
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
            if (in_array($override, ['sqs', 'cloud', 'redis', 'database', 'null', 'sync', ''], true)) {
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
            'cloud' => $this->makeCloud($connection),
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

    /**
     * Laravel Cloud's managed queues (`driver => cloud`) are a wrapper, not a
     * backend: `Illuminate\Foundation\Cloud::configureManagedQueues()` injects
     * `queue.connections.cloud` with the real connection config nested one
     * level down under `connection`, and `bootManagedQueues()` registers a
     * connector that delegates to the matching real connector. Unwrap the same
     * way — the nested array is a complete connection config in its own right
     * (`driver`, `prefix`, `suffix`, `queue`, `region`, `credentials`).
     *
     * Cloud's own keys (`queues`, `agent`, `overflow`) are not read here: they
     * describe delivery, not depth.
     *
     * SQS is the only backend Cloud wraps today; any other nested driver takes
     * the unknown-driver path rather than being guessed at.
     */
    private function makeCloud(string $connection): QueueSnapshotDriver
    {
        $cfgRaw = config("queue.connections.{$connection}", []);
        $cfg = is_array($cfgRaw) ? $cfgRaw : [];
        $nested = $cfg['connection'] ?? null;

        if (! is_array($nested)) {
            return $this->unknownDriver($connection, 'cloud (no nested connection config)');
        }

        $nestedDriver = is_string($nested['driver'] ?? null) ? $nested['driver'] : '';

        if ($nestedDriver !== 'sqs') {
            return $this->unknownDriver($connection, "cloud/{$nestedDriver}");
        }

        return $this->sqsFromConfig($connection, $nested);
    }

    private function makeSqs(string $connection): SqsSnapshotDriver
    {
        $cfgRaw = config("queue.connections.{$connection}", []);
        $cfg = is_array($cfgRaw) ? $cfgRaw : [];

        return $this->sqsFromConfig($connection, $cfg);
    }

    /**
     * Honour the connection's `credentials` provider the same way
     * `Illuminate\Queue\Connectors\SqsConnector::resolveCredentialProvider()`
     * does — Laravel Cloud's managed connection names one rather than shipping
     * a key/secret pair.
     *
     * Unlike the connector, an unrecognised provider name is ignored instead of
     * throwing: a snapshot tick failing hard over it would take out metrics the
     * SDK's own default chain would very likely have resolved anyway.
     *
     * @param  array<array-key, mixed>  $cfg
     */
    private function credentialProvider(array $cfg): ?callable
    {
        $credentials = $cfg['credentials'] ?? null;
        $provider = is_array($credentials) ? ($credentials['provider'] ?? null) : $credentials;

        if (! is_string($provider)) {
            // A callable provider is handed to the SDK as-is, same as the
            // connector does.
            return is_callable($provider) ? $provider : null;
        }

        /** @var array<string, mixed> $options */
        $options = is_array($credentials) ? array_diff_key($credentials, ['provider' => null]) : [];

        $resolved = match ($provider) {
            'ecs' => CredentialProvider::ecsCredentials($options),
            'instance' => CredentialProvider::instanceProfile($options),
            default => null,
        };

        return $resolved === null ? null : CredentialProvider::memoize($resolved);
    }

    /**
     * @param  array<array-key, mixed>  $cfg
     */
    private function sqsFromConfig(string $connection, array $cfg): SqsSnapshotDriver
    {
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

        // Provider first, then the key/secret pair — `SqsConnector::connect()`'s
        // own precedence, so the snapshot client never authenticates against a
        // different account than the worker. Neither present leaves the SDK on
        // its default credential chain, which is how Cloud and any other
        // IAM-role host authenticates.
        if (($provider = $this->credentialProvider($cfg)) !== null) {
            $clientConfig['credentials'] = $provider;
        } elseif ($credentials !== []) {
            $clientConfig['credentials'] = $credentials;
        }

        $prefix = $cfg['prefix'] ?? null;
        $suffix = $cfg['suffix'] ?? null;

        return new SqsSnapshotDriver(
            new SqsClient($clientConfig),
            $connection,
            is_string($prefix) ? $prefix : '',
            is_string($suffix) ? $suffix : '',
        );
    }
}
