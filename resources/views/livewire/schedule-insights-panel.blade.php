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
     *   list       $recentRuns      recent runs page
     *   int        $totalRuns
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
    $statusBadge = static function (string $status): array {
        return match ($status) {
            'success' => ['label' => '✓ ok', 'cls' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/30'],
            'failed'  => ['label' => '✗ failed', 'cls' => 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300 ring-red-600/20 dark:ring-red-400/30'],
            'skipped' => ['label' => '↷ skipped', 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'],
            'hung'    => ['label' => '⏳ hung', 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/30'],
            'missed'  => ['label' => '⏰ missed', 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/30'],
            'starting' => ['label' => '… running', 'cls' => 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/30'],
            default   => ['label' => $status, 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'],
        };
    };

    // task_key → human label (description ?? command). The recent-runs
    // table only carries the opaque hash; this map turns it into the
    // same one-liner operators saw in the Tasks card.
    $taskLabels = [];
    foreach ($tasksAll as $row) {
        $taskLabels[$row['task_key']] = $row['description'] ?? $row['command'];
    }

    $sparklineSuccess = array_sum(array_column($sparkline, 'success'));
    $sparklineFailed = array_sum(array_column($sparkline, 'failed'));
    $totalPages = (int) max(1, (int) ceil($totalRuns / max(1, $perPage)));
    $rangeStart = $totalRuns === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalRuns, $page * $perPage);
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

        {{-- Headline tiles --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-x-6 gap-y-4 rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10">
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
                            $tone = $bar['failed'] > 0 ? 'bg-red-300 dark:bg-red-400/60' : ($total > 0 ? 'bg-emerald-300 dark:bg-emerald-400/60' : 'bg-gray-200 dark:bg-gray-700');
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
                    <h4 class="mt-4 text-xs font-semibold tracking-wide text-red-700 dark:text-red-300">Needs attention</h4>
                    <ul role="list" class="mt-2 divide-y divide-gray-950/5 dark:divide-white/10">
                        @foreach($needsAttention as $row)
                            @php $runsTotal = max(1, (int) ($row['stats']['runs'] ?? 0)); @endphp
                            <li>
                                <button type="button"
                                        wire:click="$set('taskFilter', '{{ $row['task_key'] }}')"
                                        class="grid w-full grid-cols-12 items-center gap-3 py-2 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5 rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                                    <span class="col-span-12 sm:col-span-6 truncate font-medium text-gray-900 dark:text-gray-100">{{ $row['description'] ?? $row['command'] }}</span>
                                    <span class="col-span-6 sm:col-span-2 truncate font-mono text-xs text-gray-500 dark:text-gray-300" title="{{ $row['expression'] }}">{{ $row['expression'] }}</span>
                                    <span class="col-span-3 sm:col-span-2 text-xs tabular-nums text-red-700 dark:text-red-300">
                                        ✗{{ $row['stats']['failed'] }}<span class="text-gray-400 dark:text-gray-400"> / {{ $runsTotal }}</span>
                                    </span>
                                    <span class="col-span-3 sm:col-span-2 text-right text-xs tabular-nums text-gray-500 dark:text-gray-300">last {{ $formatTime($row['counters']['last_run_at']) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($healthy !== [])
                    <h4 class="mt-4 text-xs font-semibold tracking-wide text-emerald-700 dark:text-emerald-300">Healthy</h4>
                    <ul role="list" class="mt-2 divide-y divide-gray-950/5 dark:divide-white/10">
                        @foreach($healthy as $row)
                            <li>
                                <button type="button"
                                        wire:click="$set('taskFilter', '{{ $row['task_key'] }}')"
                                        class="grid w-full grid-cols-12 items-center gap-3 py-2 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5 rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                                    <span class="col-span-12 sm:col-span-6 truncate text-gray-900 dark:text-gray-100">{{ $row['description'] ?? $row['command'] }}</span>
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
                                <option value="{{ $row['task_key'] }}">{{ $row['description'] ?? $row['command'] }}</option>
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
                                    @php
                                        $badge = $statusBadge($run['status']);
                                        $label = $taskLabels[$run['task_key']] ?? null;
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2 align-top">
                                            @if($label !== null)
                                                <p class="truncate text-gray-900 dark:text-gray-100" title="{{ $label }}">{{ $label }}</p>
                                                <p class="font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ substr($run['task_key'], 0, 8) }}</p>
                                            @else
                                                <p class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ substr($run['task_key'], 0, 8) }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $run['host_id'] }}</td>
                                        <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $formatTime($run['started_at_ms']) }}</td>
                                        <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $formatDuration($run['runtime_ms']) }}</td>
                                        <td class="px-3 py-2 align-top tabular-nums text-gray-700 dark:text-gray-300">{{ $run['exit_code'] ?? '—' }}</td>
                                        <td class="px-3 py-2 align-top">
                                            <span class="inline-flex items-center rounded-md py-1 pr-2 pl-1.5 text-xs font-medium ring-1 ring-inset {{ $badge['cls'] }}">{{ $badge['label'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-950/5 dark:border-white/10 px-5 py-2 text-xs">
                    <p class="tabular-nums text-gray-500 dark:text-gray-300">
                        Showing
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($rangeStart) }}</span>–<span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($rangeEnd) }}</span>
                        of <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($totalRuns) }}</span>
                    </p>
                    @if($totalPages > 1)
                        <div class="flex items-center gap-1">
                            <button type="button"
                                    wire:click="$set('page', {{ max(1, $page - 1) }})"
                                    @disabled($page <= 1)
                                    class="inline-flex items-center gap-1 rounded-md bg-white dark:bg-gray-900 px-2 py-1 font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-50">
                                Prev
                            </button>
                            <span class="px-2 tabular-nums text-gray-500 dark:text-gray-300">
                                Page <span class="font-medium text-gray-700 dark:text-gray-300">{{ $page }}</span> of <span class="font-medium text-gray-700 dark:text-gray-300">{{ $totalPages }}</span>
                            </span>
                            <button type="button"
                                    wire:click="$set('page', {{ min($totalPages, $page + 1) }})"
                                    @disabled($page >= $totalPages)
                                    class="inline-flex items-center gap-1 rounded-md bg-white dark:bg-gray-900 px-2 py-1 font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-50">
                                Next
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</section>
