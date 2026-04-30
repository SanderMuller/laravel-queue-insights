<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Lazy;
use Livewire\Component;
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
    public function placeholder(): View
    {
        return ViewFactory::make('queue-insights::livewire.alert-rules-panel-placeholder');
    }

    public function render(AlertRulesPanelBuilder $builder): View
    {
        return ViewFactory::make('queue-insights::livewire.alert-rules-panel', [
            'alertRulesPanel' => $builder->build(),
        ]);
    }
}
