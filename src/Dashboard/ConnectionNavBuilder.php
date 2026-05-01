<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use SanderMuller\QueueInsights\Support\ConfiguredConnections;

/**
 * Builds the connection-nav tab strip rendered above the headline cards.
 * Reads the configured snapshot connections, filters them through the
 * optional `viewQueueInsightsConnection` Gate, and emits a tab list ready
 * for the Blade partial.
 *
 * @internal
 */
final readonly class ConnectionNavBuilder
{
    /**
     * @return array{
     *     should_render: bool,
     *     tabs: list<array{name: ?string, label: string, url: string, active: bool, tooltip: ?string}>,
     * }
     */
    public function build(?string $scopeConnection): array
    {
        $accessible = [];
        $hasGate = Gate::has('viewQueueInsightsConnection');
        $anyDenied = false;
        foreach (ConfiguredConnections::all() as $name) {
            if (! $hasGate || Gate::forUser(Auth::user())->allows('viewQueueInsightsConnection', $name)) {
                $accessible[] = $name;
            } else {
                $anyDenied = true;
            }
        }

        // Single-connection deployments and gate-restricted operators with
        // one allowed tab fall here — nothing meaningful to switch between.
        if (count($accessible) < 2) {
            return ['should_render' => false, 'tabs' => []];
        }

        // When any connection is denied, the un-scoped route 403s, so the
        // "All" tab is dropped to avoid a click-through to that 403.
        $tabs = [];
        if (! $anyDenied) {
            $tabs[] = [
                'name' => null,
                'label' => 'All',
                'url' => route('queue-insights.dashboard'),
                'active' => $scopeConnection === null,
                'tooltip' => null,
            ];
        }

        foreach ($accessible as $name) {
            $tabs[] = [
                'name' => $name,
                'label' => $name,
                'url' => route('queue-insights.connection', ['connection' => $name]),
                'active' => $scopeConnection === $name,
                'tooltip' => null,
            ];
        }

        return ['should_render' => true, 'tabs' => $tabs];
    }
}
