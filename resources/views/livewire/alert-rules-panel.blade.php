@php
    /**
     * Read-only alert-rules panel — lives in its own dashboard tab.
     * Reflects the resolved `alerts.rules` + `alerts.channels` config so
     * operators can see what's monitored without reading the config
     * file. Always-expanded; no collapsible chrome (matches the Queues /
     * Pending / Batches tab panes).
     *
     * Required scope vars:
     *   $alertRulesPanel  array{
     *       enabled: bool,
     *       cooldown_seconds: int,
     *       rules: list<array>,
     *       channels: list<array>,
     *       legacy_thresholds_in_use: bool,
     *   }
     */
@endphp
<section class="rounded-xl bg-white p-5 ring-1 ring-gray-950/5">
    <header class="flex items-center gap-3">
        <h2 class="text-sm font-semibold tracking-tight text-gray-900">Alert rules</h2>
        <span @class([
            'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset',
            'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $alertRulesPanel['enabled'],
            'bg-gray-100 text-gray-600 ring-gray-500/20' => ! $alertRulesPanel['enabled'],
        ])>
            {{ $alertRulesPanel['enabled'] ? 'enabled' : 'disabled' }}
        </span>
        <span class="text-xs text-gray-500">cooldown: {{ $alertRulesPanel['cooldown_seconds'] }}s</span>
    </header>

    @if($alertRulesPanel['legacy_thresholds_in_use'])
        <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-inset ring-amber-600/20">
            Legacy <code class="font-mono">alerts.thresholds</code> config key is in use. Move entries under
            <code class="font-mono">alerts.rules.depth.thresholds</code>.
        </p>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 lg:grid-cols-2">
        <section>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rules</h3>
            <ul role="list" class="mt-2 flex flex-col gap-2 text-sm">
                @foreach($alertRulesPanel['rules'] as $rule)
                    <li class="flex flex-col gap-1 rounded-md bg-gray-50 p-2 ring-1 ring-inset ring-gray-200">
                        <div class="flex items-center gap-2">
                            <code class="font-mono text-xs text-gray-900">{{ $rule['key'] }}</code>
                            <span @class([
                                'inline-flex items-center rounded px-1 py-0.5 text-[10px] font-medium ring-1 ring-inset',
                                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $rule['enabled'],
                                'bg-gray-100 text-gray-600 ring-gray-500/20' => ! $rule['enabled'],
                            ])>{{ $rule['enabled'] ? 'on' : 'off' }}</span>

                            @if($rule['severity'] !== null)
                                <span @class([
                                    'inline-flex items-center rounded px-1 py-0.5 text-[10px] font-medium ring-1 ring-inset',
                                    'bg-red-50 text-red-700 ring-red-600/20' => $rule['severity'] === \SanderMuller\QueueInsights\Enums\AlertSeverity::Critical,
                                    'bg-amber-50 text-amber-700 ring-amber-600/20' => $rule['severity'] !== \SanderMuller\QueueInsights\Enums\AlertSeverity::Critical,
                                ])>{{ $rule['severity']->value }}</span>
                            @endif
                        </div>
                        @if(! empty($rule['params']))
                            <dl class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-700">
                                @foreach($rule['params'] as $param)
                                    <div class="flex items-baseline gap-1">
                                        <dt class="font-medium text-gray-500">{{ $param['label'] }}:</dt>
                                        <dd class="font-mono">{{ $param['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Channels</h3>
            <ul role="list" class="mt-2 flex flex-col gap-2 text-sm">
                @foreach($alertRulesPanel['channels'] as $channel)
                    <li class="flex items-center gap-2 rounded-md bg-gray-50 p-2 ring-1 ring-inset ring-gray-200">
                        <code class="font-mono text-xs text-gray-900">{{ $channel['key'] }}</code>
                        <span @class([
                            'inline-flex items-center rounded px-1 py-0.5 text-[10px] font-medium ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $channel['enabled'],
                            'bg-gray-100 text-gray-600 ring-gray-500/20' => ! $channel['enabled'],
                        ])>{{ $channel['enabled'] ? 'on' : 'off' }}</span>
                        <span class="truncate text-xs text-gray-600">{{ $channel['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <p class="mt-3 text-xs text-gray-400">
        Read-only view of <code class="font-mono">config/queue-insights.php</code> → <code class="font-mono">alerts.*</code>. Edit the config file
        to change rules or channels.
    </p>
</section>
