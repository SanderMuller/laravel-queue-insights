@php
    /**
     * Read-only alert-rules panel — lives in its own dashboard tab.
     * Reflects the resolved `alerts.rules` + `alerts.channels` config so
     * operators can see what's monitored without reading the config file.
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
    use SanderMuller\QueueInsights\Enums\AlertSeverity;

    $rules = $alertRulesPanel['rules'];
    $channels = $alertRulesPanel['channels'];
    $enabled = $alertRulesPanel['enabled'];
    $cooldown = $alertRulesPanel['cooldown_seconds'];
    $legacy = $alertRulesPanel['legacy_thresholds_in_use'];

    $firingRules = array_values(array_filter($rules, fn (array $r): bool => $r['firing_count'] > 0));
    $activeRules = array_values(array_filter($rules, fn (array $r): bool => $r['enabled'] && $r['firing_count'] === 0));
    $inactiveRules = array_values(array_filter($rules, fn (array $r): bool => ! $r['enabled']));

    $totalFiring = array_sum(array_map(fn (array $r): int => $r['firing_count'], $rules));
    $criticalFiring = array_sum(array_map(
        fn (array $r): int => $r['firing_severity'] === AlertSeverity::Critical ? $r['firing_count'] : 0,
        $rules,
    ));
@endphp

<section
    @if(\SanderMuller\QueueInsights\Support\Config::bool('dashboard.polling', true))
        {{-- `.visible` pauses polling when the section is off-screen
             (display:none from Alpine `x-show` on a different tab, or
             scrolled out of view) so this lazy child doesn't double the
             dashboard's request rate after the operator switches away. --}}
        wire:poll.10s.visible
    @endif
    class="flex flex-col gap-4"
>
    {{-- Header: title + enabled + cooldown + firing summary, channels as inline pills --}}
    <div class="rounded-xl bg-white p-5 ring-1 ring-gray-950/5">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h2 class="text-base font-semibold tracking-tight text-gray-900">Alerting</h2>
            <span @class([
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $enabled,
                'bg-gray-100 text-gray-600 ring-gray-500/20' => ! $enabled,
            ])>{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
            <span class="text-xs text-gray-500">cooldown {{ $cooldown }}s</span>
            @if($totalFiring > 0)
                <span class="text-xs text-gray-500 tabular-nums">
                    <span @class(['font-semibold', 'text-red-700' => $criticalFiring > 0, 'text-amber-700' => $criticalFiring === 0])>{{ $totalFiring }}</span> in alarm
                </span>
            @endif
        </div>

        @if($legacy)
            <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-inset ring-amber-600/20">
                Legacy <code class="font-mono">alerts.thresholds</code> config key is in use. Move entries under
                <code class="font-mono">alerts.rules.depth.thresholds</code>.
            </p>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Channels:</span>
            @foreach($channels as $channel)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 py-1 pl-2 pr-2.5 text-xs ring-1 ring-inset ring-gray-200">
                    <span @class([
                        'inline-flex size-1.5 rounded-full',
                        'bg-emerald-500' => $channel['enabled'],
                        'bg-gray-300' => ! $channel['enabled'],
                    ])></span>
                    <code class="font-mono text-xs text-gray-900">{{ $channel['key'] }}</code>
                    <span class="text-gray-500">{{ $channel['detail'] }}</span>
                </span>
            @endforeach
        </div>
    </div>

    {{-- In alarm — AWS CloudWatch terminology. Each rule row expands to
         show the underlying per-(connection, queue) issues so operators see
         exactly what tripped. --}}
    @if($firingRules !== [])
        @php
            $flatIssues = [];
            foreach ($firingRules as $rule) {
                foreach ($rule['firing_issues'] as $issue) {
                    $flatIssues[] = $issue + ['rule_key' => $rule['key']];
                }
            }

            $shortClass = static function (string $s): string {
                if (! str_contains($s, '\\')) {
                    return $s;
                }
                $parts = explode('\\', $s);
                return end($parts) ?: $s;
            };

            $sinceLabelFn = static function (int $age): string {
                if ($age < 60) return "{$age}s";
                if ($age < 3600) return floor($age / 60) . 'm';
                return floor($age / 3600) . 'h';
            };
        @endphp

        {{-- Flat table; click a row to expand inline detail. One row per
             firing issue — rule, target, age. Expanded panel reveals the
             full title, description, and detector context. --}}
        <section x-data="{ open: null }" class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/5">
            <div class="flex items-center justify-between border-b border-gray-950/5 px-5 py-3">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900">In alarm</h3>
                <span class="text-xs text-gray-500 tabular-nums">{{ $totalFiring }} active issue{{ $totalFiring === 1 ? '' : 's' }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50/60 text-left text-[10px] uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="w-8 py-2 pl-5"></th>
                            <th scope="col" class="py-2 pr-4 font-medium">Sev</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Rule</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Target</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Detail</th>
                            <th scope="col" class="py-2 pr-5 text-right font-medium">Age</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5">
                        @foreach($flatIssues as $i => $issue)
                            @php
                                $issueCritical = $issue['severity'] === AlertSeverity::Critical;
                                $tgt = $issue['target_type'] === 'class' ? $shortClass($issue['target']) : $issue['target'];
                            @endphp
                            <tr x-on:click="open = (open === {{ $i }} ? null : {{ $i }})"
                                class="cursor-pointer hover:bg-gray-50/60"
                                x-bind:class="open === {{ $i }} ? 'bg-gray-50' : ''">
                                <td class="py-2 pl-5">
                                    <svg class="size-4 text-gray-400 transition" x-bind:class="open === {{ $i }} ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                                </td>
                                <td class="py-2 pr-4">
                                    <span @class([
                                        'inline-flex items-center gap-1.5 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide',
                                        'bg-red-50 text-red-700' => $issueCritical,
                                        'bg-amber-50 text-amber-700' => ! $issueCritical,
                                    ])>
                                        <span @class([
                                            'inline-flex size-1.5 rounded-full',
                                            'bg-red-500' => $issueCritical,
                                            'bg-amber-500' => ! $issueCritical,
                                        ])></span>
                                        {{ $issueCritical ? 'crit' : 'warn' }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4"><code class="font-mono text-xs font-medium text-gray-900">{{ $issue['rule_key'] }}</code></td>
                                <td class="py-2 pr-4"><span class="font-mono text-xs text-gray-700" title="{{ $issue['target'] }}">{{ $tgt }}</span></td>
                                <td class="py-2 pr-4 text-xs text-gray-600"><span class="line-clamp-1">{{ $issue['description'] }}</span></td>
                                <td class="py-2 pr-5 text-right text-xs tabular-nums text-gray-500">{{ $sinceLabelFn($issue['age_seconds']) }}</td>
                            </tr>
                            <tr x-show="open === {{ $i }}" x-cloak>
                                <td colspan="6" @class([
                                    'border-l-2 px-5 py-3 text-xs',
                                    'border-red-400 bg-red-50/40' => $issueCritical,
                                    'border-amber-400 bg-amber-50/40' => ! $issueCritical,
                                ])>
                                    <p class="font-medium text-gray-900">{{ $issue['title'] }}</p>
                                    <p class="mt-1 text-gray-700">{{ $issue['description'] }}</p>
                                    @if(! empty($issue['context']))
                                        <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-0.5 sm:grid-cols-3">
                                            @foreach($issue['context'] as $ctxKey => $ctxValue)
                                                <div class="flex items-baseline gap-1.5">
                                                    <dt class="text-gray-500">{{ $ctxKey }}</dt>
                                                    <dd class="font-mono tabular-nums text-gray-800">{{ $ctxValue }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <section class="rounded-xl bg-white p-5 ring-1 ring-gray-950/5">
            <div class="flex items-center gap-2 text-sm">
                <span class="inline-flex size-2 rounded-full bg-emerald-500"></span>
                <p class="font-medium text-gray-900">All alarms OK.</p>
            </div>
            <p class="mt-1 text-xs text-gray-500">No active rules have crossed their thresholds.</p>
        </section>
    @endif

    {{-- OK — enabled rules within thresholds --}}
    @if($activeRules !== [])
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/5">
            <div class="flex items-center justify-between border-b border-gray-950/5 px-5 py-3">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900">OK</h3>
                <span class="text-xs text-gray-500 tabular-nums">{{ count($activeRules) }} watching</span>
            </div>
            <ul role="list" class="divide-y divide-gray-950/5">
                @foreach($activeRules as $rule)
                    <li class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-baseline sm:gap-6">
                        <div class="flex items-center gap-2 sm:w-48 sm:shrink-0">
                            <code class="font-mono text-sm font-medium text-gray-900">{{ $rule['key'] }}</code>
                            @if($rule['severity'] !== null)
                                <span @class([
                                    'inline-flex items-center rounded px-1 py-0.5 text-[10px] font-medium ring-1 ring-inset',
                                    'bg-red-50 text-red-700 ring-red-600/20' => $rule['severity'] === AlertSeverity::Critical,
                                    'bg-amber-50 text-amber-700 ring-amber-600/20' => $rule['severity'] !== AlertSeverity::Critical,
                                ])>{{ $rule['severity']->value }}</span>
                            @endif
                        </div>
                        @if(! empty($rule['params']))
                            <dl class="flex flex-1 flex-wrap gap-x-4 gap-y-0.5 text-xs">
                                @foreach($rule['params'] as $param)
                                    <div class="flex items-baseline gap-1.5">
                                        <dt class="font-medium text-gray-500">{{ $param['label'] }}</dt>
                                        <dd class="font-mono text-gray-700">{{ $param['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @else
                            <span class="text-xs text-gray-400">no parameters</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Disabled — actions disabled for these rules --}}
    @if($inactiveRules !== [])
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/5">
            <div class="flex items-center justify-between border-b border-gray-950/5 px-5 py-3">
                <h3 class="text-sm font-semibold tracking-tight text-gray-500">Disabled</h3>
                <span class="text-xs text-gray-500 tabular-nums">{{ count($inactiveRules) }} off</span>
            </div>
            <ul role="list" class="flex flex-wrap gap-2 px-5 py-3">
                @foreach($inactiveRules as $rule)
                    <li>
                        <code class="rounded bg-gray-50 px-2 py-0.5 font-mono text-xs text-gray-500 ring-1 ring-inset ring-gray-200">{{ $rule['key'] }}</code>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="text-xs text-gray-400">
        Read-only view of <code class="font-mono">config/queue-insights.php</code> → <code class="font-mono">alerts.*</code>. Edit the config file to change rules or channels.
    </p>
</section>
