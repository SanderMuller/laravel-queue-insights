<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use SanderMuller\QueueInsights\Support\Config;

/**
 * Builds the option lists shown in the filter dropdowns. Connection
 * and queue come from the configured snapshots (the package's source
 * of truth for what's tracked); class comes from the 24h class roster
 * passed in by the caller.
 *
 * @internal
 */
final readonly class FilterOptionsBuilder
{
    /**
     * @param  list<array<string, mixed>>  $classes
     * @param  ?string  $scopeConnection  when non-null, the connections list
     *                                    is omitted (the dropdown hides anyway —
     *                                    scope already pins the connection),
     *                                    and queues are restricted to the
     *                                    scoped connection.
     * @return array{connections: list<string>, queues: list<string>, classes: list<string>}
     */
    public function build(array $classes, ?string $scopeConnection = null): array
    {
        $snapshots = array_values(array_filter(Config::array('snapshots'), is_array(...)));

        if ($scopeConnection !== null) {
            $snapshots = array_values(array_filter(
                $snapshots,
                static fn (array $s): bool => ($s['connection'] ?? null) === $scopeConnection,
            ));
        }

        return [
            'connections' => $scopeConnection !== null
                ? []
                : $this->distinctStrings(array_column($snapshots, 'connection')),
            'queues' => $this->distinctStrings(array_column($snapshots, 'queue')),
            'classes' => $this->distinctStrings(array_column($classes, 'class')),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function distinctStrings(array $values): array
    {
        $out = array_values(array_unique(array_filter(
            $values,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        )));
        sort($out);

        return $out;
    }
}
