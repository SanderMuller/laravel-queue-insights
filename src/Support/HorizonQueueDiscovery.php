<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;

/**
 * Resolve Horizon supervisor `{connection, queue}` tuples from
 * `horizon.environments` + `horizon.defaults`. Mirrors Horizon's own
 * `ProvisioningPlan` semantics (Str::is glob on env keys, first match wins,
 * then `array_replace_recursive($defaults, $matched)` for the supervisor map)
 * so discovery surfaces exactly what Horizon would deploy.
 *
 * Stateless. Safe under Octane.
 */
final class HorizonQueueDiscovery
{
    /** @return list<array{connection: string, queue: string}> */
    public static function discover(): array
    {
        if (! class_exists(Horizon::class)) {
            return [];
        }

        $configured = Config::string('horizon.environment');
        $envKey = $configured !== '' ? $configured : (string) app()->environment();

        $environments = self::readBlock('horizon.environments');
        $defaults = self::readBlock('horizon.defaults');

        $matchedSupervisors = null;
        foreach ($environments as $candidateKey => $supervisors) {
            if (! is_string($candidateKey)) {
                continue;
            }

            if (! is_array($supervisors)) {
                continue;
            }

            if (Str::is($candidateKey, $envKey)) {
                $matchedSupervisors = $supervisors;
                break;
            }
        }

        if ($matchedSupervisors === null) {
            return [];
        }

        $merged = array_replace_recursive($defaults, $matchedSupervisors);

        $out = [];
        foreach ($merged as $supervisor) {
            if (! is_array($supervisor)) {
                continue;
            }

            $connection = $supervisor['connection'] ?? null;
            $queues = $supervisor['queue'] ?? null;
            if (! is_string($connection)) {
                continue;
            }

            if ($connection === '') {
                continue;
            }

            $list = match (true) {
                is_array($queues) => $queues,
                is_string($queues) => explode(',', $queues),
                default => [],
            };

            foreach ($list as $queue) {
                if (! is_string($queue)) {
                    continue;
                }

                $queue = trim($queue);
                if ($queue === '') {
                    continue;
                }

                $out[] = ['connection' => $connection, 'queue' => $queue];
            }
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    private static function readBlock(string $key): array
    {
        $value = config($key, []);

        return is_array($value) ? $value : [];
    }
}
