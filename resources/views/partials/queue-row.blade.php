@php
    /** @var array<string, mixed> $q */
    /** @var int $pendingGapWarnThreshold */
    /** @var ?string $selectedQueue */
    $depthNum = is_numeric($q['depth']) ? (int) $q['depth'] : 0;
    $depthCls = $depthNum === 0 ? 'text-gray-900 dark:text-gray-100' : ($depthNum > 1000 ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300');

    $pendingGapWarnThreshold = $pendingGapWarnThreshold ?? 5;
    $hasInspector = ! ($q['inspector_disabled'] ?? true)
        && ((($q['tracked_count'] ?? 0) > 0) || ($q['inspector_open'] ?? false));
    $gap = $q['pending_gap'] ?? 0;
    $gapBadge = $gap > $pendingGapWarnThreshold;

    // Row selection mirrors `selectedClass === $c['class']` from job-classes-section.
    // `inspector_key` is the canonical `'{connection}:{queue}'` shape — same shape
    // as `$selectedQueue`, so an exact-match check avoids re-canonicalising here.
    $isSelected = ($selectedQueue ?? '') !== '' && ($selectedQueue ?? '') === ($q['inspector_key'] ?? '');

    // Selected highlight is suppressed under error / stale so those critical
    // states keep their tonal cue. Mirrors job-classes-section (no error/stale
    // surface in that table — single state).
    $rowBg = $q['error']
        ? 'bg-red-50/30 dark:bg-red-900/20'
        : ($q['stale']
            ? 'bg-amber-50/30 dark:bg-amber-900/20'
            : ($isSelected ? 'bg-emerald-50/30 dark:bg-emerald-900/20' : ''));
@endphp
<li class="{{ $rowBg }} cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500 dark:hover:bg-white/5 dark:focus-visible:bg-emerald-900/30"
    role="button"
    tabindex="0"
    aria-label="Filter all tabs by queue {{ $q['queue'] }}"
    wire:click="selectQueue(@js($q['connection']), @js($q['queue']))"
    x-on:keydown.enter.prevent.self="$wire.selectQueue(@js($q['connection']), @js($q['queue']))"
    x-on:keydown.space.prevent.self="$wire.selectQueue(@js($q['connection']), @js($q['queue']))">
    <div class="grid grid-cols-12 items-center gap-4 px-4 py-3">
        <div class="col-span-4 min-w-0">
            <p class="truncate text-sm text-gray-500 dark:text-gray-300">{{ $q['connection'] }}</p>
            <p class="truncate font-mono text-sm font-medium text-gray-900 dark:text-gray-100">{{ $q['queue'] }}</p>
        </div>
        <dl class="col-span-4 grid grid-cols-3 text-center text-sm tabular-nums">
            <div>
                <dt class="sr-only">Depth</dt>
                <dd class="font-semibold {{ $depthCls }}">{{ $q['depth'] }}</dd>
            </div>
            <div>
                <dt class="sr-only">In-flight</dt>
                @php
                    $inflightNum = is_numeric($q['inflight'] ?? null) ? (int) $q['inflight'] : 0;
                @endphp
                <dd class="inline-flex items-center justify-center gap-3 font-semibold text-gray-900 dark:text-gray-100">
                    @if($inflightNum > 0)
                        {{-- Live radar micro-pulse — emerald rings echo the
                             Aurora header chrome. Only when work is actually
                             in-flight so the rest of the table stays quiet. --}}
                        <span class="relative inline-flex" aria-hidden="true">
                            <span class="pointer-events-none absolute -inset-1.5">
                                <span class="absolute inset-0 rounded-full ring-1 ring-emerald-400/50" style="animation: qi-radar-sm 2s ease-out infinite;"></span>
                                <span class="absolute inset-0 rounded-full ring-1 ring-emerald-400/50" style="animation: qi-radar-sm 2s ease-out infinite; animation-delay: 1s;"></span>
                            </span>
                            <span class="relative inline-flex size-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                        </span>
                    @endif
                    <span>{{ $q['inflight'] ?? '—' }}</span>
                </dd>
            </div>
            <div>
                <dt class="sr-only">Delayed</dt>
                <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $q['delayed'] ?? '—' }}</dd>
            </div>
        </dl>
        <dl class="col-span-2 text-sm tabular-nums text-gray-500 dark:text-gray-300" title="Wait time = enqueue → worker pickup. Most recent 1000 jobs.">
            <div class="flex items-center justify-end gap-1.5">
                <dt class="text-gray-400 dark:text-gray-400">p50</dt>
                <dd class="font-medium text-gray-700 dark:text-gray-300">{{ $q['wait_p50_ms'] !== null ? number_format($q['wait_p50_ms']).'ms' : '—' }}</dd>
            </div>
            <div class="flex items-center justify-end gap-1.5">
                <dt class="text-gray-400 dark:text-gray-400">p95</dt>
                <dd class="font-medium text-gray-700 dark:text-gray-300">{{ $q['wait_p95_ms'] !== null ? number_format($q['wait_p95_ms']).'ms' : '—' }}</dd>
            </div>
        </dl>
        <div class="col-span-2 flex flex-wrap items-center justify-end gap-1.5 text-sm">
            @if($q['error'])
                <x-queue-insights::hint triggerClass="rounded-md bg-red-50 dark:bg-red-900/40 px-1.5 py-0.5 font-medium text-red-700 dark:text-red-300 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30 cursor-help">
                    error
                    <x-slot:tip>
                        Most recent snapshot for this queue raised an error. The driver-reported message: <code class="rounded bg-white/10 px-1 font-mono">{{ $q['error'] }}</code>. Check the worker host can reach the queue backend (SQS credentials, Redis connectivity, DB grants) and look for a stack trace in the Laravel log alongside the next <code class="rounded bg-white/10 px-1 font-mono">queue-insights:snapshot</code> run.
                    </x-slot:tip>
                </x-queue-insights::hint>
            @endif
            @if($q['stale'])
                <x-queue-insights::hint triggerClass="rounded-md bg-amber-50 dark:bg-amber-900/40 px-1.5 py-0.5 font-medium text-amber-700 dark:text-amber-300 ring-1 ring-inset ring-amber-600/20 dark:ring-amber-400/30 cursor-help">
                    stale
                    <x-slot:tip>
                        Last snapshot is older than 120s. The <code class="rounded bg-white/10 px-1 font-mono">queue-insights:snapshot</code> command should run every minute via Laravel's scheduler &mdash; check <code class="rounded bg-white/10 px-1 font-mono">schedule:run</code> is wired into cron and the worker host can reach Redis.
                    </x-slot:tip>
                </x-queue-insights::hint>
            @endif
            <span class="rounded-md bg-gray-950/5 dark:bg-white/10 px-1.5 py-0.5 font-mono text-gray-700 dark:text-gray-300">{{ $q['driver'] }}</span>

            @if($hasInspector)
                <button type="button"
                        wire:click.stop="toggleQueueInspector(@js($q['inspector_key']))"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-gray-500 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-700 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                        aria-label="{{ $q['inspector_open'] ? 'Collapse pending inspector' : 'Expand pending inspector' }}">
                    <x-queue-insights::icon-chevron-right class="size-3 transition-transform {{ $q['inspector_open'] ? 'rotate-90' : '' }}"/>
                    <span class="tabular-nums">{{ number_format($q['tracked_count']) }} queued</span>
                    @if($gapBadge)
                        <span class="ml-1 rounded bg-red-50 dark:bg-red-900/40 px-1 py-px font-medium text-red-700 dark:text-red-300">+{{ number_format($gap) }} gap</span>
                    @endif
                </button>
            @endif

            @if($q['last_at'])
                <x-queue-insights::qi-time :at="$q['last_at']" prefix="last" class="basis-full text-right text-sm text-gray-400 dark:text-gray-400"/>
            @else
                <span class="basis-full text-right text-sm text-gray-400 dark:text-gray-400">
                    <x-queue-insights::hint placement="bottom" triggerClass="cursor-help underline decoration-dotted decoration-gray-300 underline-offset-2">
                        no snapshot yet
                        <x-slot:tip>
                            No depth snapshot has been recorded for this queue yet. The <code class="rounded bg-white/10 px-1 font-mono">queue-insights:snapshot</code> command writes one each minute &mdash; make sure Laravel's scheduler is running (<code class="rounded bg-white/10 px-1 font-mono">* * * * * php artisan schedule:run</code>) and <code class="rounded bg-white/10 px-1 font-mono">queue-insights.schedule.enabled</code> is <code class="rounded bg-white/10 px-1 font-mono">true</code>. If it has been more than a minute, check the logs for snapshot errors.
                        </x-slot:tip>
                    </x-queue-insights::hint>
                </span>
            @endif
        </div>
    </div>

    @if(! empty($q['inspector_open']))
        <div class="border-t border-gray-950/5 dark:border-white/10 bg-gray-50/70 dark:bg-white/[0.03] px-4 py-3">
            @if($gapBadge)
                <p class="mb-2 text-xs text-red-700 dark:text-red-300">
                    <strong>Tracking gap.</strong> {{ number_format($gap) }} job{{ $gap === 1 ? '' : 's' }} on the queue {{ $gap === 1 ? 'is' : 'are' }} not in our pending tracking — the lists below are a sample, not a complete enumeration. Trust the queue counters (above) for totals.
                </p>
            @endif

            @if(empty($q['pending_jobs']) && empty($q['delayed_jobs']))
                <p class="text-xs text-gray-500 dark:text-gray-300">No pending or delayed jobs tracked for this queue.</p>
            @else
                @if(! empty($q['pending_jobs']))
                    <h4 class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-300">Pending ({{ count($q['pending_jobs']) }})</h4>
                    <ul role="list" class="mb-3 divide-y divide-gray-950/5 dark:divide-white/10 overflow-hidden rounded bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10">
                        @foreach($q['pending_jobs'] as $job)
                            @php
                                $queuedAt = (int) ($job['queued_at'] ?? 0);
                            @endphp
                            <li class="flex items-center justify-between gap-3 px-3 py-1.5 text-xs">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="truncate font-mono text-gray-900 dark:text-gray-100">{{ $job['class'] ?? '—' }}</span>
                                    @if(! empty($job['batch_id']))
                                        @include('queue-insights::partials.batch-chip', ['batchId' => $job['batch_id']])
                                    @endif
                                </span>
                                <x-queue-insights::qi-time :at="$queuedAt" prefix="queued" class="shrink-0 text-gray-500 dark:text-gray-300"/>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if(! empty($q['delayed_jobs']))
                    <h4 class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-300">Delayed ({{ count($q['delayed_jobs']) }})</h4>
                    <ul role="list" class="divide-y divide-gray-950/5 dark:divide-white/10 overflow-hidden rounded bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10">
                        @foreach($q['delayed_jobs'] as $job)
                            @php
                                $availableAt = (int) ($job['available_at'] ?? 0);
                            @endphp
                            <li class="flex items-center justify-between gap-3 px-3 py-1.5 text-xs">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="truncate font-mono text-gray-900 dark:text-gray-100">{{ $job['class'] ?? '—' }}</span>
                                    @if(! empty($job['batch_id']))
                                        @include('queue-insights::partials.batch-chip', ['batchId' => $job['batch_id']])
                                    @endif
                                </span>
                                <x-queue-insights::qi-time :at="$availableAt" prefix="runs" class="shrink-0 text-gray-500 dark:text-gray-300"/>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    @endif
</li>
