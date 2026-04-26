<div wire:poll.10s class="flex flex-col gap-10">
    {{-- Dashboard content wrapper — made `inert` when the details modal is open so AT
        users don't hear the background dashboard and keyboard focus can't escape the
        modal. MUST be a sibling of the modal (not an ancestor), or the modal itself
        would be inerted. See Resolved Q #13 + #16. --}}
    <div id="qi-dashboard-content"
         class="flex flex-col gap-8"
         x-data x-bind:inert="$wire.selectedPayloadId !== null || $wire.selectedFailedId !== null">

        <x-queue-insights::flash-banner/>

        {{-- Top stat strip — Horizon-style "current workload" row. Only live-state sums
            live here (Queues / Depth / In-flight). 24h Processed/Failed totals are in
            the throughput card header above, no duplicate read. --}}
        @php
            $totalDepth = array_sum(array_map(fn ($q): int => is_numeric($q['depth']) ? (int) $q['depth'] : 0, $queues));
            $totalInFlight = array_sum(array_map(fn ($q): int => is_numeric($q['inflight'] ?? null) ? (int) $q['inflight'] : 0, $queues));
        @endphp

        <x-queue-insights::throughput-sparkline :throughput="$throughput"/>

        {{-- Current-workload stat strip. Processed/Failed 24h totals intentionally live
            in the throughput card above — avoid the duplicate read. --}}
        <dl aria-label="Current workload"
            class="grid grid-cols-1 gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5 sm:grid-cols-3">
            <div class="bg-white p-5">
                <dt class="truncate text-xs font-medium text-gray-500">Queues</dt>
                <dd class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 tabular-nums">{{ count($queues) }}</dd>
            </div>
            <div class="bg-white p-5">
                <dt class="truncate text-xs font-medium text-gray-500">Depth</dt>
                <dd class="mt-1 text-2xl font-semibold tracking-tight tabular-nums {{ $totalDepth > 0 ? 'text-emerald-700' : 'text-gray-900' }}">{{ number_format($totalDepth) }}</dd>
            </div>
            <div class="bg-white p-5">
                <dt class="truncate text-xs font-medium text-gray-500">In-flight</dt>
                <dd class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 tabular-nums">{{ number_format($totalInFlight) }}</dd>
            </div>
        </dl>

        {{-- Queue cards --}}
        <section>
            <div class="mb-5 flex items-center gap-2.5">
                <span class="h-5 w-1 rounded bg-emerald-500" aria-hidden="true"></span>
                <h2 class="text-base font-semibold tracking-tight text-gray-900">Queues</h2>
            </div>
            @if(count($queues) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                    No queues configured. Add entries to <code
                        class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs">config/queue-insights.php</code>
                    under <code class="rounded bg-gray-950/5 px-1 py-0.5 font-mono text-xs">snapshots</code>.
                </div>
            @else
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($queues as $q)
                        <div class="rounded-lg bg-white p-5 ring-1 ring-gray-950/5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-medium text-gray-500">{{ $q['connection'] }}</p>
                                    <p class="truncate font-mono text-sm text-gray-900">{{ $q['queue'] }}</p>
                                </div>
                                <span
                                    class="shrink-0 rounded bg-gray-950/5 px-2 py-0.5 font-mono text-xs text-gray-700">
                                {{ $q['driver'] }}
                            </span>
                            </div>

                            <dl class="mt-5 grid grid-cols-3 text-center">
                                <div
                                    class="pr-2 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-gray-950/5 [&:not(:first-child)]:pl-2">
                                    <dt class="text-xs font-medium text-gray-500">Depth</dt>
                                    <dd class="mt-1 text-2xl font-semibold tabular-nums {{ is_numeric($q['depth']) && (int) $q['depth'] > 0 ? 'text-emerald-700' : 'text-gray-900' }}">{{ $q['depth'] }}</dd>
                                </div>
                                <div
                                    class="px-2 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-gray-950/5">
                                    <dt class="text-xs font-medium text-gray-500">In-flight</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $q['inflight'] ?? '—' }}</dd>
                                </div>
                                <div
                                    class="pl-2 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-gray-950/5">
                                    <dt class="text-xs font-medium text-gray-500">Delayed</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $q['delayed'] ?? '—' }}</dd>
                                </div>
                            </dl>

                            {{-- Wait-time micro-stats — p50 / p95 over the last 1000 jobs.
                                Renders `—` when fewer than 10 samples have accumulated yet. --}}
                            <dl class="mt-3 flex items-center gap-3 text-[11px] tabular-nums text-gray-500"
                                title="Wait time = enqueue → worker pickup. Computed over the most recent 1000 jobs on this queue.">
                                <div class="flex items-center gap-1">
                                    <dt class="text-gray-400">wait p50</dt>
                                    <dd class="font-medium text-gray-700">{{ $q['wait_p50_ms'] !== null ? number_format($q['wait_p50_ms']).' ms' : '—' }}</dd>
                                </div>
                                <span class="text-gray-300" aria-hidden="true">·</span>
                                <div class="flex items-center gap-1">
                                    <dt class="text-gray-400">p95</dt>
                                    <dd class="font-medium text-gray-700">{{ $q['wait_p95_ms'] !== null ? number_format($q['wait_p95_ms']).' ms' : '—' }}</dd>
                                </div>
                            </dl>

                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                @if($q['error'])
                                    <span
                                        class="rounded bg-red-50 px-2 py-0.5 font-medium text-red-700 ring-1 ring-inset ring-red-600/20"
                                        title="{{ $q['error'] }}">
                                    error
                                </span>
                                @endif
                                @if($q['stale'])
                                    <span
                                        class="rounded bg-amber-50 px-2 py-0.5 font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">stale</span>
                                @endif
                                @if($q['last_at'])
                                    <span class="text-gray-500" title="{{ $q['last_at']->toIso8601String() }}">last {{ $q['last_at']->diffForHumans() }}</span>
                                @else
                                    <span class="text-gray-500">no snapshot yet</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Recent completed --}}
        <section>
            <div class="mb-5 flex items-center gap-2.5">
                <span class="h-5 w-1 rounded bg-emerald-500" aria-hidden="true"></span>
                <h2 class="text-base font-semibold tracking-tight text-gray-900">Recent completed</h2>
                @if($selectedClass)
                    <span class="font-mono text-xs text-gray-500">({{ $selectedClass }})</span>
                @endif
            </div>
            @if(count($completedRows) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                    No completed jobs recorded yet.
                </div>
            @else
                <div class="-mx-6 -my-2 overflow-x-auto sm:-mx-8 lg:-mx-10">
                    <div class="inline-block min-w-full px-6 py-2 align-middle sm:px-8 lg:px-10">
                        <table class="w-full text-sm">
                            <thead>
                            <tr class="text-left text-xs font-medium text-gray-500">
                                <th class="whitespace-nowrap py-2 pr-3 font-medium">Job</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">On</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Runtime</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">Completed</th>
                                <th class="whitespace-nowrap py-2 pl-3 font-medium"><span class="sr-only">Details</span></th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-950/5">
                            @foreach($completedRows as $row)
                                @php
                                    $runtime = $row['duration_ms'] ?? '';
                                    $runtimeShort = is_numeric($runtime) && (int) $runtime > 0
                                        ? \Carbon\CarbonInterval::milliseconds((int) $runtime)->cascade()->forHumans(['short' => true])
                                        : '—';
                                    $attempts = is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : null;
                                    $processedAt = $row['processed_at'] ?? null;
                                    try {
                                        $atHuman = is_string($processedAt) && $processedAt !== ''
                                            ? \Illuminate\Support\Facades\Date::parse($processedAt)->diffForHumans()
                                            : null;
                                    } catch (\Throwable) {
                                        $atHuman = null;
                                    }
                                @endphp
                                <tr class="cursor-pointer transition hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Open job details"
                                    wire:click="openPayload(@js($row['_id']))"
                                    x-on:keydown.enter.prevent="$wire.openPayload(@js($row['_id']))"
                                    x-on:keydown.space.prevent="$wire.openPayload(@js($row['_id']))">
                                    {{-- Job: two-line display — full class name on top, short stream id below. --}}
                                    <td class="max-w-md py-3 pr-3 align-top">
                                        <p class="truncate font-mono text-xs font-medium text-gray-900">{{ $row['class'] ?? $selectedClass ?? '—' }}</p>
                                        @if (! empty($row['short_id']))
                                            <p class="mt-0.5 font-mono text-[10px] text-gray-400">#{{ $row['short_id'] }}</p>
                                        @endif
                                    </td>
                                    {{-- On: connection · queue pill pair, vertical stack. --}}
                                    <td class="px-3 py-3 align-top">
                                        <p class="text-xs text-gray-500">{{ $row['connection'] ?? '—' }}</p>
                                        <p class="mt-0.5 font-mono text-xs text-gray-800">{{ $row['queue'] ?? '—' }}</p>
                                    </td>
                                    {{-- Runtime + attempts --}}
                                    <td class="px-3 py-3 text-right align-top">
                                        <p class="text-sm font-medium tabular-nums text-gray-900">{{ $runtimeShort }}</p>
                                        @if ($attempts !== null && $attempts > 1)
                                            <p class="mt-0.5 text-[10px] font-medium tabular-nums text-amber-700">{{ $attempts }} attempts</p>
                                        @endif
                                    </td>
                                    {{-- Completed at — humanized + absolute tooltip --}}
                                    <td class="px-3 py-3 align-top">
                                        <p class="whitespace-nowrap text-xs text-gray-700" @if ($processedAt) title="{{ $processedAt }}" @endif>{{ $atHuman ?? '—' }}</p>
                                        @if ($processedAt)
                                            <p class="mt-0.5 truncate font-mono text-[10px] text-gray-400">{{ $processedAt }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pl-3 align-top text-right text-[10px] uppercase tracking-wider text-gray-400">
                                        Open
                                        <svg class="ml-0.5 inline-block size-3 -translate-y-px" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                                        </svg>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        {{-- Recent failed --}}
        <section>
            <div class="mb-3 flex flex-wrap items-center gap-2.5">
                <span class="h-5 w-1 rounded bg-emerald-500" aria-hidden="true"></span>
                <h2 class="text-base font-semibold tracking-tight text-gray-900">Recent failed</h2>
                @if($failedFiltersActive)
                    <span class="rounded bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">filtered</span>
                @endif

                {{-- Bulk Retry — visible only when the host defines retryFailedJobs,
                    user has the gate, filters are active, AND the matching set fits
                    inside the 100-row cap. Server enforces all three rules in
                    retryFailedBulk() regardless of UI state. Two-click confirm
                    (Alpine state — first click flips label, second click fires). --}}
                @if($canRetry && $failedFiltersActive && $bulkRetryCount !== null && $bulkRetryCount > 0)
                    <div class="ml-auto" x-data="{ confirming: false, t: null }"
                         x-on:click.outside="confirming = false">
                        @if($bulkRetryCount > 100)
                            <span class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10"
                                  title="Bulk retry rejects sets larger than 100 — narrow the filter.">
                                {{ $bulkRetryCount }} matches · narrow to retry
                            </span>
                        @else
                            <button type="button"
                                    x-bind:class="confirming
                                        ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500'
                                        : 'bg-white text-emerald-700 ring-emerald-600/30 hover:bg-emerald-50'"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                                    x-on:click="
                                        if (! confirming) {
                                            confirming = true;
                                            t = setTimeout(() => confirming = false, 2500);
                                            return;
                                        }
                                        clearTimeout(t);
                                        confirming = false;
                                        $wire.retryFailedBulk();
                                    ">
                                <span x-show="! confirming">Retry {{ $bulkRetryCount }} job{{ $bulkRetryCount === 1 ? '' : 's' }}</span>
                                <span x-show="confirming" x-cloak>Confirm retry?</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            <details class="mb-4 group" @if($failedFiltersActive) open @endif>
                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <span>Filter</span>
                    <svg class="size-3 transition-transform group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                    </svg>
                </summary>

                <div class="mt-3 grid grid-cols-1 gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-inset ring-gray-950/5 sm:grid-cols-5">
                    <label class="flex flex-col gap-1 text-[10px] font-medium uppercase tracking-wider text-gray-500">
                        Connection
                        <input type="text" wire:model.live.debounce.300ms="filterConnection"
                               placeholder="any"
                               class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
                    </label>
                    <label class="flex flex-col gap-1 text-[10px] font-medium uppercase tracking-wider text-gray-500">
                        Queue
                        <input type="text" wire:model.live.debounce.300ms="filterQueue"
                               placeholder="any"
                               class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
                    </label>
                    <label class="flex flex-col gap-1 text-[10px] font-medium uppercase tracking-wider text-gray-500 sm:col-span-1">
                        Class (prefix)
                        <input type="text" wire:model.live.debounce.300ms="filterClass"
                               placeholder="App\\Jobs\\…"
                               class="rounded-md border-0 bg-white px-2 py-1.5 font-mono text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
                    </label>
                    <label class="flex flex-col gap-1 text-[10px] font-medium uppercase tracking-wider text-gray-500">
                        From
                        <input type="date" wire:model.live="filterFrom"
                               class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
                    </label>
                    <label class="flex flex-col gap-1 text-[10px] font-medium uppercase tracking-wider text-gray-500">
                        To
                        <input type="date" wire:model.live="filterTo"
                               class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
                    </label>

                    @if($failedFiltersActive)
                        <div class="sm:col-span-5 -mt-1 flex justify-end">
                            <button type="button" wire:click="clearFailedFilters"
                                    class="rounded text-xs font-medium text-emerald-700 hover:text-emerald-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                                Clear all filters
                            </button>
                        </div>
                    @endif
                </div>
            </details>

            @if(count($failedRows) === 0)
                <div class="rounded-lg border border-dashed border-gray-950/10 p-6 text-sm text-gray-500">
                    @if($failedFiltersActive)
                        No failed jobs match the current filters.
                    @else
                        No failed jobs recorded.
                    @endif
                </div>
            @else
                <div class="-mx-6 px-6 -my-2 overflow-x-auto sm:-mx-8 lg:-mx-10">
                    <div class="inline-block min-w-full px-6 py-2 align-middle sm:px-8 lg:px-10">
                        <table class="w-full text-sm">
                            <thead>
                            <tr class="text-left text-xs font-medium text-gray-500">
                                <th class="whitespace-nowrap py-2 pr-3 font-medium" colspan="2">Job</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">On</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">Failed</th>
                                <th class="whitespace-nowrap py-2 pl-3 font-medium"><span class="sr-only">Details</span></th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-950/5">
                            @foreach ($failedRows as $f)
                                @php
                                    try {
                                        $failedAtHuman = is_string($f['failed_at'] ?? null) && $f['failed_at'] !== ''
                                            ? \Illuminate\Support\Facades\Date::parse($f['failed_at'])->diffForHumans()
                                            : null;
                                    } catch (\Throwable) {
                                        $failedAtHuman = null;
                                    }
                                @endphp
                                <tr @class([
                                        'transition',
                                        'cursor-pointer hover:bg-gray-950/[0.03] focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500' => $f['id'] !== null,
                                    ])
                                    @if ($f['id'] !== null)
                                        role="button"
                                        tabindex="0"
                                        aria-label="Open failed job details"
                                        wire:click="openFailed({{ $f['id'] }})"
                                        x-on:keydown.enter.prevent="$wire.openFailed({{ $f['id'] }})"
                                        x-on:keydown.space.prevent="$wire.openFailed({{ $f['id'] }})"
                                    @endif>
                                    {{-- Icon column: red circle-exclamation for scannability --}}
                                    <td class="py-3 pr-3 align-top">
                                        <span class="inline-flex size-7 items-center justify-center rounded-full bg-red-50 text-red-600 ring-1 ring-inset ring-red-600/20" aria-hidden="true">
                                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" clip-rule="evenodd"/>
                                            </svg>
                                        </span>
                                    </td>
                                    {{-- Job: two-line — displayName (primary) + exception class + short uuid (secondary) --}}
                                    <td class="max-w-md py-3 pr-3 align-top">
                                        <p class="truncate font-mono text-xs font-medium text-gray-900">{{ $f['display_name'] ?? '—' }}</p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-[11px]">
                                            @if ($f['exception_class'])
                                                <span class="truncate font-mono font-medium text-red-600" title="{{ $f['exception_message'] }}">{{ $f['exception_class'] }}</span>
                                            @endif
                                            @if ($f['short_uuid'])
                                                <span class="text-gray-300" aria-hidden="true">·</span>
                                                <span class="font-mono text-[10px] text-gray-400">#{{ $f['short_uuid'] }}</span>
                                            @endif
                                        </p>
                                    </td>
                                    {{-- On: connection + queue stacked --}}
                                    <td class="px-3 py-3 align-top">
                                        <p class="text-xs text-gray-500">{{ $f['connection'] ?? '—' }}</p>
                                        <p class="mt-0.5 font-mono text-xs text-gray-800">{{ $f['queue'] ?? '—' }}</p>
                                    </td>
                                    {{-- Failed at — humanized + absolute --}}
                                    <td class="px-3 py-3 align-top">
                                        <p class="whitespace-nowrap text-xs text-gray-700" @if ($f['failed_at']) title="{{ $f['failed_at'] }}" @endif>{{ $failedAtHuman ?? '—' }}</p>
                                        @if ($f['failed_at'])
                                            <p class="mt-0.5 truncate font-mono text-[10px] text-gray-400">{{ $f['failed_at'] }}</p>
                                        @endif
                                        @if ($f['attempts'] !== null && $f['max_tries'] !== null)
                                            <p class="mt-0.5 text-[10px] font-medium tabular-nums text-gray-500">{{ $f['attempts'] }}/{{ $f['max_tries'] }} attempts</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pl-3 align-top text-right text-[10px] uppercase tracking-wider text-gray-400">
                                        @if ($f['id'] !== null)
                                            Open
                                            <svg class="ml-0.5 inline-block size-3 -translate-y-px" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                                            </svg>
                                        @else
                                            <span>—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        <x-queue-insights::job-classes-section :classes="$classes" :selected-class="$selectedClass"/>

    </div>{{-- /#qi-dashboard-content --}}

    {{-- Details modal (completed jobs) --}}
    @if($selectedPayload !== null)
        <x-queue-insights::details-modal
            :payload="$selectedPayload"
            :payload-tab="$payloadTab"
            :capture-mode="$captureMode"/>
    @endif

    {{-- Failed-job detail modal --}}
    @if($selectedFailed !== null)
        <x-queue-insights::failed-modal :failed="$selectedFailed" :can-retry="$canRetry"/>
    @endif
</div>

