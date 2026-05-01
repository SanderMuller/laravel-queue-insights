<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use SanderMuller\QueueInsights\Alerts\ActiveIssuesProvider;
use SanderMuller\QueueInsights\Dashboard\AlertRulesPanelBuilder;

/**
 * Lazy-mounted alert-rules panel — split off from the main dashboard so its
 * config-flatten work (and the views/builders that back it) only runs when
 * the operator actually opens the "Alert rules" tab. The parent
 * `QueueInsightsDashboard` initial render skips this panel entirely; the
 * placeholder loads first, Livewire's lazy-mount cycle then hydrates the
 * full panel in a follow-up request.
 */
#[Lazy]
final class AlertRulesPanel extends Component
{
    /**
     * Active connection scope, propagated from the parent dashboard. Null
     * means "All" (un-scoped); a string narrows the rendered rules to the
     * matching depth thresholds.
     */
    public ?string $scopeConnection = null;

    public function mount(?string $scopeConnection = null): void
    {
        $this->scopeConnection = $scopeConnection;
    }

    public function placeholder(): View
    {
        return ViewFactory::make('queue-insights::livewire.alert-rules-panel-placeholder');
    }

    public function render(AlertRulesPanelBuilder $builder, ActiveIssuesProvider $activeIssues): View
    {
        return ViewFactory::make('queue-insights::livewire.alert-rules-panel', [
            'alertRulesPanel' => $builder->build(
                $this->scopeConnection,
                $activeIssues->get($this->scopeConnection),
            ),
        ]);
    }
}
