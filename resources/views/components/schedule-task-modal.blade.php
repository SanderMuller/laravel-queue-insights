@props([
    /**
     * Snapshot row for the task (description, command, expression,
     * timezone, runInBackground, onOneServer, evenInMaintenanceMode,
     * withoutOverlapping, mutexName, type) — same shape as
     * `ScheduleReader::tasks()` rows.
     *
     * @var array<string, mixed>
     */
    'task' => [],
    /**
     * Window stats for this task.
     *
     * @var array{runs: int, failed: int, skipped: int, hung: int, missed: int, last_run_at_ms: ?int, p95_ms: ?int}
     */
    'stats' => ['runs' => 0, 'failed' => 0, 'skipped' => 0, 'hung' => 0, 'missed' => 0, 'last_run_at_ms' => null, 'p95_ms' => null],
    /**
     * host_id → run count, sorted desc.
     *
     * @var array<string, int>
     */
    'hostDistribution' => [],
    /**
     * Recent runs scoped to this task. Same row shape as `ScheduleReader::recentRuns`.
     *
     * @var list<array<string, mixed>>
     */
    'recentRuns' => [],
])

@php
    use Cron\CronExpression;
    use Illuminate\Support\Facades\Date;
    use Illuminate\Support\Str;
    use SanderMuller\QueueInsights\Scheduler\AggregatesQuery;

    $taskKey = is_string($task['task_key'] ?? null) ? $task['task_key'] : '';
    $description = is_string($task['description'] ?? null) && $task['description'] !== ''
        ? $task['description']
        : (is_string($task['command'] ?? null) ? $task['command'] : '—');
    $command = is_string($task['command'] ?? null) ? $task['command'] : '';
    $expression = is_string($task['expression'] ?? null) && $task['expression'] !== ''
        ? $task['expression']
        : '* * * * *';
    $timezone = is_string($task['timezone'] ?? null) && $task['timezone'] !== ''
        ? $task['timezone']
        : null;
    $type = is_string($task['type'] ?? null) ? $task['type'] : 'command';
    $isClosure = $type === 'closure';

    // Next-due — `dragonmantank/cron-expression` is already in scope via
    // Laravel; same call shape MissedRunReconciler uses.
    $nextDueAt = null;
    try {
        $cron = new CronExpression($expression);
        $nextDueAt = $cron->getNextRunDate('now', 0, false, $timezone)->getTimestamp() * 1000;
    } catch (\Throwable) {
        // Malformed cron — leave $nextDueAt null; the tile shows '—'.
    }

    $formatDuration = static function (?int $ms): string {
        if ($ms === null || $ms <= 0) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return number_format($ms / 1000, 2) . 's';
    };
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-schedule-task-modal-title"
     x-data
     x-on:keydown.escape.window="$wire.closeTaskModal()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closeTaskModal">
    <div x-trap.noscroll="true"
         class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
            <h3 id="qi-schedule-task-modal-title" class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                Scheduled task
            </h3>
            <button type="button"
                    wire:click="closeTaskModal"
                    aria-label="Close scheduled task modal"
                    class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <x-queue-insights::icon-close/>
            </button>
        </div>

        <div class="p-4">
            {{-- Identity hero --}}
            <section data-section="schedule-task-identity" class="mb-6">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Task</p>
                <div class="rounded-xl bg-linear-to-br from-gray-50 to-white p-4 ring-1 ring-gray-950/5 dark:from-gray-800 dark:to-gray-900 dark:ring-white/10">
                    <dl>
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Description</dt>
                        <dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-gray-100">{{ $description }}</dd>
                        @if($command !== '' && $command !== $description)
                            <dt class="mt-3 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Command</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $command }}</dd>
                        @endif
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="Cron" :value="$expression"/>
                        @if($timezone !== null)
                            <x-queue-insights::meta-pill label="TZ" :value="$timezone"/>
                        @endif
                        <x-queue-insights::meta-pill label="Type" :value="$type"/>
                    </div>
                    {{-- Flag pills — only render the truthy ones to keep the row tight. --}}
                    @php
                        $flags = array_filter([
                            'runInBackground' => (bool) ($task['runInBackground'] ?? false),
                            'onOneServer' => (bool) ($task['onOneServer'] ?? false),
                            'withoutOverlapping' => (bool) ($task['withoutOverlapping'] ?? false),
                            'evenInMaintenanceMode' => (bool) ($task['evenInMaintenanceMode'] ?? false),
                        ]);
                    @endphp
                    @if($flags !== [])
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                            @foreach(array_keys($flags) as $flag)
                                <span class="inline-flex items-center rounded-md bg-emerald-50 dark:bg-emerald-900/40 px-2 py-0.5 font-mono text-[11px] text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-600/20 dark:ring-emerald-400/30">
                                    {{ $flag }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Closure capture hint — surfaced for closure tasks only.
                    Reads `type === 'closure'` from the snapshot row, NOT
                    from a live `instanceof CallbackEvent` check (CLAUDE.md
                    rule: snapshot is authoritative). --}}
                @if($isClosure)
                    <div class="mt-3 flex gap-3 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-xs leading-5 text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
                        <x-queue-insights::icon-info-circle class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-400"/>
                        <p>
                            Output capture not supported by Laravel for closure tasks. Log inside the closure with
                            <code class="rounded bg-white dark:bg-gray-900 px-1 font-mono ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">Log::info(...)</code>
                            if you need stdout.
                        </p>
                    </div>
                @endif
            </section>

            @php $attentionReasons = AggregatesQuery::attentionReasons($stats); @endphp
            @if($attentionReasons !== [])
                <section data-section="schedule-task-attention" class="mb-6 rounded-xl bg-red-50 dark:bg-red-900/30 p-4 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30">
                    <p class="text-[10px] font-medium uppercase tracking-wider text-red-700 dark:text-red-300">Needs attention</p>
                    <ul role="list" class="mt-2 flex flex-col gap-1 text-sm text-red-900 dark:text-red-200">
                        @foreach($attentionReasons as $reason)
                            <li class="flex items-center gap-2 tabular-nums">
                                @switch($reason['kind'])
                                    @case('failed')
                                        <svg class="size-4 shrink-0 text-red-600 dark:text-red-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.78-9.22a.75.75 0 0 0-1.06-1.06L10 10.44 7.28 7.72a.75.75 0 0 0-1.06 1.06L8.94 11.5 6.22 14.22a.75.75 0 1 0 1.06 1.06L10 12.56l2.72 2.72a.75.75 0 1 0 1.06-1.06L11.06 11.5l2.72-2.72Z" clip-rule="evenodd"/>
                                        </svg>
                                        @break
                                    @case('hung')
                                        <svg class="size-4 shrink-0 text-amber-600 dark:text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM9.25 5.5a.75.75 0 0 1 1.5 0v4.69l3.03 3.03a.75.75 0 1 1-1.06 1.06L9.47 10.78a.75.75 0 0 1-.22-.53V5.5Z" clip-rule="evenodd"/>
                                        </svg>
                                        @break
                                    @case('missed')
                                        <x-queue-insights::icon-warning-triangle class="size-4 shrink-0 text-amber-600 dark:text-amber-300"/>
                                        @break
                                @endswitch
                                <span class="font-semibold">{{ number_format($reason['count']) }}</span>
                                <span>{{ Str::plural('run', $reason['count']) }} {{ $reason['kind'] }} in the past 24h</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-[11px] leading-5 text-red-700/80 dark:text-red-300/80">
                        Click a row in <strong>Recent runs</strong> below to open the per-run drilldown — exception block (failed), skip reason (missed), or runtime details (hung).
                    </p>
                </section>
            @endif

            {{-- Window stats grid --}}
            <section data-section="schedule-task-stats" class="mb-6">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Past 24h</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-x-6 gap-y-4 rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    @include('queue-insights::partials.stat-tile', ['label' => 'Runs', 'value' => number_format($stats['runs']), 'sub' => 'past 24h', 'tone' => 'neutral'])
                    @include('queue-insights::partials.stat-tile', ['label' => 'Failed', 'value' => number_format($stats['failed']), 'sub' => 'past 24h', 'tone' => $stats['failed'] > 0 ? 'danger' : 'neutral'])
                    @include('queue-insights::partials.stat-tile', ['label' => 'Hung', 'value' => number_format($stats['hung']), 'sub' => 'past 24h', 'tone' => $stats['hung'] > 0 ? 'warn' : 'neutral'])
                    @include('queue-insights::partials.stat-tile', ['label' => 'Skipped', 'value' => number_format($stats['skipped']), 'sub' => 'past 24h', 'tone' => 'neutral'])
                    @include('queue-insights::partials.stat-tile', ['label' => 'Missed', 'value' => number_format($stats['missed']), 'sub' => 'past 24h', 'tone' => $stats['missed'] > 0 ? 'warn' : 'neutral'])
                    @include('queue-insights::partials.stat-tile', ['label' => 'p95 runtime', 'value' => $formatDuration($stats['p95_ms']), 'sub' => 'rolling', 'tone' => 'neutral'])
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-gray-950/5 dark:bg-white/10 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="bg-white dark:bg-gray-900 p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Last run</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            <x-queue-insights::qi-time :at="$stats['last_run_at_ms']"/>
                        </dd>
                    </div>
                    <div class="bg-white dark:bg-gray-900 p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Next due</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            @if($nextDueAt !== null)
                                <x-queue-insights::qi-time :at="(int) $nextDueAt"/>
                            @else
                                <span class="text-gray-400 dark:text-gray-400">—</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </section>

            {{-- Host distribution (suppressed for one-host tasks) --}}
            @include('queue-insights::partials.schedule-host-distribution', ['distribution' => $hostDistribution])

            {{-- Recent runs scoped to this task --}}
            <section data-section="schedule-task-recent-runs" class="mt-6">
                <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Recent runs</p>
                @if($recentRuns === [])
                    <p class="mt-2 rounded-lg bg-gray-50 dark:bg-gray-800 px-3 py-2 text-[11px] text-gray-500 dark:text-gray-300 ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
                        No runs in the recent window.
                    </p>
                @else
                    <div class="mt-2 -my-2 overflow-x-auto whitespace-nowrap">
                        <div class="inline-block min-w-full py-2 align-middle">
                            <table class="min-w-full text-left text-xs">
                                <thead class="text-[11px] text-gray-500 dark:text-gray-300">
                                    <tr>
                                        <th class="whitespace-nowrap px-3 py-1 font-medium">Host</th>
                                        <th class="whitespace-nowrap px-3 py-1 font-medium">Started</th>
                                        <th class="whitespace-nowrap px-3 py-1 font-medium">Runtime</th>
                                        <th class="whitespace-nowrap px-3 py-1 font-medium">Exit</th>
                                        <th class="whitespace-nowrap px-3 py-1 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                                    @foreach($recentRuns as $run)
                                        @include('queue-insights::partials.schedule-run-row', [
                                            'run' => $run,
                                            'showTask' => false,
                                        ])
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
