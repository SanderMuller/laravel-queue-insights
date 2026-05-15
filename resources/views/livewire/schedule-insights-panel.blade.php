@php
    /**
     * Schedule observability panel — Phase 2 of the cron-monitoring spec.
     *
     * Required scope vars:
     *   bool       $enabled
     *   ?int       $snapshotAtMs
     *   bool       $snapshotStale
     *   array      $headlineStats   runs/failed/skipped/hung/missed/p95
     *   list       $sparkline       per-hour {hour, success, failed}
     *   list       $needsAttention  task rows with at least one failure/hang/miss
     *   list       $healthy         task rows with no failures
     *   list       $tasksAll        union for the filter dropdown
     *   list       $recentRuns      recent runs page (also wrapped in $runsPaginator)
     *   int        $totalRuns
     *   \Illuminate\Pagination\LengthAwarePaginator $runsPaginator
     *   list<int>  $perPageOptions
     *   list       $distinctHosts
     *   string     $taskFilter / $statusFilter / $hostFilter / $from / $to
     *   int        $perPage / $page
     */

    use Illuminate\Support\Facades\Date;

    $formatTime = static function (?int $ms): string {
        if ($ms === null || $ms <= 0) {
            return '—';
        }
        return Date::createFromTimestamp((int) ($ms / 1000))->diffForHumans();
    };
    $formatDuration = static function (?int $ms): string {
        if ($ms === null || $ms <= 0) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return number_format($ms / 1000, 2) . 's';
    };
    // task_key → human label (description ?? short(command)). The recent-runs
    // table only carries the opaque hash; this map turns it into the same
    // one-liner operators saw in the Tasks card. Commands are shortened
    // (`'/Users/.../bin/php' 'artisan' …` → `php artisan …`) for list
    // surfaces — the drilldown modal still shows the full unmodified
    // command so debugging an unusual binary path stays possible.
    $taskLabels = [];
    foreach ($tasksAll as $row) {
        $taskLabels[$row['task_key']] = ($row['description'] !== null && $row['description'] !== '')
            ? $row['description']
            : \SanderMuller\QueueInsights\Scheduler\CommandLabel::short($row['command']);
    }

    $sparklineSuccess = array_sum(array_column($sparkline, 'success'));
    $sparklineFailed = array_sum(array_column($sparkline, 'failed'));
@endphp

<section class="flex flex-col gap-4">
    @if(! $enabled)
        <div class="rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Scheduler observability is disabled. Set
                <code class="rounded bg-gray-100 dark:bg-gray-800 px-1 py-0.5 text-xs">queue-insights.scheduler.enabled = true</code>
                and restart your workers to enable.
            </p>
        </div>
    @else
        {{-- Section status strip — snapshot freshness + stale warning --}}
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-300">
            <div class="flex items-center gap-2 tabular-nums">
                <span class="inline-flex size-1.5 rounded-full {{ $snapshotStale ? 'bg-amber-500' : 'bg-emerald-500' }}" aria-hidden="true"></span>
                <span>
                    @if($snapshotAtMs !== null)
                        Snapshot {{ $formatTime($snapshotAtMs) }}
                    @else
                        No snapshot yet
                    @endif
                </span>
            </div>
            <div class="tabular-nums">
                <span class="text-emerald-700 dark:text-emerald-300">✓ {{ number_format($headlineStats['runs_24h'] - $headlineStats['failed_24h']) }}</span>
                <span class="mx-1.5 text-gray-300 dark:text-gray-500">·</span>
                <span class="text-red-700 dark:text-red-300">✗ {{ number_format($headlineStats['failed_24h']) }}</span>
                <span class="ml-1.5">past 24h</span>
            </div>
        </div>

        @if($snapshotStale)
            <div class="rounded-xl bg-amber-50 dark:bg-amber-900/40 p-3 ring-1 ring-amber-600/20 dark:ring-amber-400/30 text-sm text-amber-800 dark:text-amber-200">
                <strong class="font-semibold">Snapshot is older than an hour.</strong>
                The package rebuilds it on app boot — restart workers / php-fpm to refresh.
            </div>
        @endif

        {{-- Headline tiles — Aurora accent mirrors the main dashboard hero:
             emerald glow + diagonal shimmer + top hairline + `live · past 24h`
             caption (only when the snapshot isn't stale, so the chrome doesn't
             lie about freshness). --}}
        <div class="relative isolate overflow-hidden grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-x-6 gap-y-4 rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10">
            @unless($snapshotStale)
                @include('queue-insights::partials.aurora-bg')
                <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-400/40 to-transparent" aria-hidden="true"></div>
                <div class="col-span-2 sm:col-span-3 lg:col-span-6 -mb-1 flex items-center gap-2">
                    <span class="relative flex size-1.5">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-1.5 rounded-full bg-emerald-400"></span>
                    </span>
                    <span class="text-[10px] font-medium uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300/80">live · past 24h</span>
                </div>
            @endunless
            @include('queue-insights::partials.stat-tile', ['label' => 'Runs', 'value' => number_format($headlineStats['runs_24h']), 'sub' => 'past 24h', 'tone' => 'neutral'])
            @include('queue-insights::partials.stat-tile', ['label' => 'Failed', 'value' => number_format($headlineStats['failed_24h']), 'sub' => 'past 24h', 'tone' => $headlineStats['failed_24h'] > 0 ? 'danger' : 'neutral'])
            @include('queue-insights::partials.stat-tile', ['label' => 'Skipped', 'value' => number_format($headlineStats['skipped_24h']), 'sub' => 'past 24h', 'tone' => 'neutral'])
            @include('queue-insights::partials.stat-tile', ['label' => 'Hung', 'value' => number_format($headlineStats['hung_24h']), 'sub' => 'past 24h', 'tone' => $headlineStats['hung_24h'] > 0 ? 'warn' : 'neutral'])
            @include('queue-insights::partials.stat-tile', ['label' => 'Missed', 'value' => number_format($headlineStats['missed_24h']), 'sub' => 'past 24h', 'tone' => $headlineStats['missed_24h'] > 0 ? 'warn' : 'neutral'])
            @include('queue-insights::partials.stat-tile', ['label' => 'Runtime p95', 'value' => $formatDuration($headlineStats['p95_runtime_ms']), 'sub' => 'rolling', 'tone' => 'neutral'])
        </div>

        {{-- Sparkline --}}
        @if($sparkline !== [])
            <div class="rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10">
                <header class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Hourly throughput</h3>
                    <p class="text-xs tabular-nums text-gray-500 dark:text-gray-300">
                        <span class="text-emerald-700 dark:text-emerald-300">✓ {{ number_format($sparklineSuccess) }}</span>
                        <span class="mx-1.5 text-gray-300 dark:text-gray-500">·</span>
                        <span class="text-red-700 dark:text-red-300">✗ {{ number_format($sparklineFailed) }}</span>
                        <span class="ml-1.5 text-gray-400 dark:text-gray-400">over last 24h</span>
                    </p>
                </header>
                <ol role="list" class="mt-4 grid grid-cols-12 sm:grid-cols-24 gap-1 text-[10px] tabular-nums">
                    @php $maxBar = max(1, max(array_map(fn (array $b) => $b['success'] + $b['failed'], $sparkline))); @endphp
                    @foreach($sparkline as $i => $bar)
                        @php
                            $total = $bar['success'] + $bar['failed'];
                            $pct = (int) round(($total / $maxBar) * 100);
                            $tone = $bar['failed'] > 0
                                ? 'bg-gradient-to-t from-rose-600 to-rose-300 dark:from-rose-500 dark:to-rose-300'
                                : ($total > 0
                                    ? 'bg-gradient-to-t from-emerald-600 to-emerald-300 dark:from-emerald-500 dark:to-emerald-300'
                                    : 'bg-gray-200 dark:bg-gray-700');
                            $showLabel = ($i % 4 === 0);
                            $barHeight = max(4, (int) round($pct / 2));
                        @endphp
                        <li class="flex flex-col items-center gap-1" title="{{ $bar['hour'] }} | {{ $bar['success'] }} ✓ {{ $bar['failed'] }} ✗">
                            <span class="block w-full rounded-sm bg-gray-100 dark:bg-gray-800 [--bar:--spacing(12)] h-(--bar)">
                                <span class="block w-full rounded-sm {{ $tone }} h-(--bar-h)" style="--bar-h: {{ $barHeight }}px;"></span>
                            </span>
                            <span class="text-gray-400 dark:text-gray-500 {{ $showLabel ? '' : 'sm:invisible' }}">{{ $bar['hour'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- Tasks: needs attention + healthy --}}
        <div class="rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10">
            <header class="flex items-baseline justify-between gap-3">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Tasks</h3>
                @if($tasksAll !== [])
                    <span class="text-xs tabular-nums text-gray-400 dark:text-gray-400">{{ count($tasksAll) }} captured</span>
                @endif
            </header>

            @if($tasksAll === [])
                <div class="mt-6 flex flex-col items-center gap-2 py-6 text-center">
                    <span class="text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true">⏱</span>
                    <p class="text-sm text-gray-500 dark:text-gray-300">
                        No scheduled tasks captured.
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-400">
                        The snapshot is rebuilt on app boot — restart workers and refresh.
                    </p>
                </div>
            @else
                @if($needsAttention !== [])
                    <h4 class="mt-4 text-xs font-semibold tracking-wide text-red-700 dark:text-red-300">Needs attention <span class="tabular-nums text-red-700/70 dark:text-red-300/70">({{ count($needsAttention) }})</span></h4>
                    <ul role="list" class="mt-2 divide-y divide-gray-950/5 dark:divide-white/10">
                        @foreach($needsAttention as $row)
                            @php $runsTotal = max(1, (int) ($row['stats']['runs'] ?? 0)); @endphp
                            <li>
                                <button type="button"
                                        wire:click="openTaskModal('{{ $row['task_key'] }}')"
                                        class="grid w-full grid-cols-12 items-center gap-3 py-2 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5 rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                                    <span class="col-span-12 sm:col-span-6 truncate font-medium text-gray-900 dark:text-gray-100">{{ $taskLabels[$row['task_key']] ?? $row['command'] }}</span>
                                    <span class="col-span-6 sm:col-span-2 truncate font-mono text-xs text-gray-500 dark:text-gray-300" title="{{ $row['expression'] }}">{{ $row['expression'] }}</span>
                                    <span class="col-span-3 sm:col-span-2 text-xs tabular-nums text-red-700 dark:text-red-300">
                                        ✗{{ $row['stats']['failed'] }}<span class="text-gray-400 dark:text-gray-400"> / {{ $runsTotal }}</span>
                                    </span>
                                    <span class="col-span-3 sm:col-span-2 text-right text-xs tabular-nums text-gray-500 dark:text-gray-300">last {{ $formatTime($row['stats']['last_run_at_ms']) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($healthy !== [])
                    <h4 class="mt-4 text-xs font-semibold tracking-wide text-emerald-700 dark:text-emerald-300">Healthy <span class="tabular-nums text-emerald-700/70 dark:text-emerald-300/70">({{ count($healthy) }})</span></h4>
                    <ul role="list" class="mt-2 divide-y divide-gray-950/5 dark:divide-white/10">
                        @foreach($healthy as $row)
                            <li>
                                <button type="button"
                                        wire:click="openTaskModal('{{ $row['task_key'] }}')"
                                        class="grid w-full grid-cols-12 items-center gap-3 py-2 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5 rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                                    <span class="col-span-12 sm:col-span-6 truncate text-gray-900 dark:text-gray-100">{{ $taskLabels[$row['task_key']] ?? $row['command'] }}</span>
                                    <span class="col-span-6 sm:col-span-2 truncate font-mono text-xs text-gray-500 dark:text-gray-300" title="{{ $row['expression'] }}">{{ $row['expression'] }}</span>
                                    <span class="col-span-3 sm:col-span-2 text-xs tabular-nums text-emerald-700 dark:text-emerald-300">✓{{ $row['stats']['runs'] }}</span>
                                    <span class="col-span-3 sm:col-span-2 text-right text-xs tabular-nums text-gray-500 dark:text-gray-300">p95 {{ $formatDuration($row['stats']['p95_ms']) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>

        {{-- Filter row + recent runs --}}
        <div class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10">
            <header class="flex flex-wrap items-end gap-3 p-5 pb-3">
                <h3 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">Recent runs</h3>
                <div class="ml-auto flex flex-wrap items-end gap-2 text-xs">
                    <label class="flex flex-col gap-1">
                        <span class="text-gray-500 dark:text-gray-300">Task</span>
                        <select wire:model.live="taskFilter" class="h-8 rounded-md border-0 bg-white dark:bg-gray-900 px-2 text-xs text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                            <option value="">all</option>
                            @foreach($tasksAll as $row)
                                <option value="{{ $row['task_key'] }}">{{ $taskLabels[$row['task_key']] ?? $row['command'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-gray-500 dark:text-gray-300">Status</span>
                        <select wire:model.live="statusFilter" class="h-8 rounded-md border-0 bg-white dark:bg-gray-900 px-2 text-xs text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                            <option value="">all</option>
                            <option value="success">success</option>
                            <option value="failed">failed</option>
                            <option value="skipped">skipped</option>
                            <option value="hung">hung</option>
                            <option value="missed">missed</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-gray-500 dark:text-gray-300">Host</span>
                        <select wire:model.live="hostFilter" class="h-8 rounded-md border-0 bg-white dark:bg-gray-900 px-2 text-xs text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                            <option value="">all</option>
                            @foreach($distinctHosts as $host)
                                <option value="{{ $host }}">{{ $host }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-gray-500 dark:text-gray-300">From</span>
                        <input type="date" wire:model.live="from" class="h-8 rounded-md border-0 bg-white dark:bg-gray-900 px-2 text-xs text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-gray-500 dark:text-gray-300">To</span>
                        <input type="date" wire:model.live="to" class="h-8 rounded-md border-0 bg-white dark:bg-gray-900 px-2 text-xs text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                    </label>
                    <button type="button" wire:click="clearFilters" class="h-8 self-end rounded-md bg-white dark:bg-gray-900 px-2 text-xs font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-gray-100 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                        clear
                    </button>
                </div>
            </header>

            @if($recentRuns === [])
                <p class="px-5 pb-5 text-sm text-gray-500 dark:text-gray-300">No runs in the selected window.</p>
            @else
                <div class="-my-2 overflow-x-auto whitespace-nowrap">
                    <div class="inline-block min-w-full px-5 py-2 align-middle">
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs text-gray-500 dark:text-gray-300">
                                <tr>
                                    <th class="whitespace-nowrap px-3 py-2 font-medium">Task</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-medium">Host</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-medium">Started</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-medium">Runtime</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-medium">Exit</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                                @foreach($recentRuns as $run)
                                    @include('queue-insights::partials.schedule-run-row', [
                                        'run' => $run,
                                        'showTask' => true,
                                        'taskLabels' => $taskLabels,
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @include('queue-insights::partials.pagination-controls', [
                    'paginator' => $runsPaginator,
                    'gotoMethod' => 'gotoRunsPage',
                    'perPageModel' => 'perPage',
                    'perPageOptions' => $perPageOptions,
                ])
            @endif
        </div>
    @endif

    {{-- Drilldown modals — rendered as siblings of the panel content
         so the backdrop covers the whole viewport. Each one is gated
         on its slot being non-empty; closing zeroes the slot. --}}
    @if($selectedTask !== null)
        <x-queue-insights::schedule-task-modal
            :task="$selectedTask"
            :stats="$selectedTask['stats']"
            :hostDistribution="$selectedTaskHosts"
            :recentRuns="$selectedTaskRuns"/>
    @endif

    @if($selectedRunId !== '')
        <x-queue-insights::schedule-run-modal
            :run="$selectedRun"
            :output="$selectedRunOutput"
            :taskLabel="$selectedRunTaskLabel"
            :isClosure="$selectedRunIsClosure"/>
    @endif
</section>
