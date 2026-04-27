@props([
    /** @var array<string, mixed>|null Pending row from `QueueInsights::allPendingJobs()` / `allDelayedJobs()`. */
    'pending' => null,
    /** Currently-open batch id, '' if none. Drives the "Back to batch" button so the user can return to the batch view they came from. */
    'expandedBatchId' => '',
])

@php
    $row = is_array($pending) ? $pending : null;
    $class = is_string($row['class'] ?? null) ? $row['class'] : null;
    $uuid = is_string($row['uuid'] ?? null) ? $row['uuid'] : null;
    $connection = is_string($row['connection'] ?? null) ? $row['connection'] : null;
    $queue = is_string($row['queue'] ?? null) ? $row['queue'] : null;
    $batchId = is_string($row['batch_id'] ?? null) ? $row['batch_id'] : null;
    $state = is_string($row['state'] ?? null) ? $row['state'] : null;

    $queuedAt = is_numeric($row['queued_at'] ?? null) ? (int) $row['queued_at'] : 0;
    $availableAt = is_numeric($row['available_at'] ?? null) ? (int) $row['available_at'] : 0;
    $startedAt = is_numeric($row['started_at'] ?? null) ? (int) $row['started_at'] : 0;
    $queuedCarbon = $queuedAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($queuedAt) : null;
    $availableCarbon = $availableAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($availableAt) : null;
    $startedCarbon = $startedAt > 0 ? \Illuminate\Support\Facades\Date::createFromTimestamp($startedAt) : null;

    $now = \Illuminate\Support\Facades\Date::now()->getTimestamp();
    $isInFlight = $state === 'in_flight';
    // `available_at <= now` is a pending-now job; `>` is delayed. The boundary
    // uses the same convention as `PendingJobsReader::allDelayed`. In-flight
    // takes precedence — a worker has it.
    $isDelayed = ! $isInFlight && $availableAt > $now;
    $waitingForSec = $queuedAt > 0 ? max(0, $now - $queuedAt) : null;
    $runningForSec = $isInFlight && $startedAt > 0 ? max(0, $now - $startedAt) : null;

    $waitingForHumanized = $waitingForSec !== null
        ? \Carbon\CarbonInterval::seconds($waitingForSec)->cascade()->forHumans(['short' => true])
        : null;
    $runningForHumanized = $runningForSec !== null
        ? \Carbon\CarbonInterval::seconds($runningForSec)->cascade()->forHumans(['short' => true])
        : null;

    $headerLabel = match (true) {
        $isInFlight => 'In-flight job',
        $isDelayed => 'Delayed job',
        default => 'Pending job',
    };
    $headerHeroLabel = match (true) {
        $isInFlight => 'Running',
        $isDelayed => 'Delayed',
        default => 'Pending',
    };
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-pending-modal-title"
     x-data
     x-on:keydown.escape.window="$wire.closePending()"
     class="fixed inset-0 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closePending">
    <div x-trap.noscroll="true"
         class="max-h-[85vh] w-full max-w-2xl overflow-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 bg-white px-4 py-4">
            <div class="flex items-center gap-2">
                @if ($expandedBatchId !== '')
                    <button type="button"
                            wire:click="closePending"
                            aria-label="Back to batch"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/>
                        </svg>
                        <span>Back to batch</span>
                    </button>
                @endif
                <span class="inline-flex size-6 items-center justify-center rounded-md ring-1 ring-inset {{ match (true) {
                    $isInFlight => 'bg-amber-50 text-amber-600 ring-amber-600/20',
                    $isDelayed => 'bg-indigo-50 text-indigo-600 ring-indigo-600/20',
                    default => 'bg-gray-50 text-gray-600 ring-gray-600/20',
                } }}">
                    @if ($isInFlight)
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm6.39-2.908a.75.75 0 0 1 .766.027l3.5 2.25a.75.75 0 0 1 0 1.262l-3.5 2.25A.75.75 0 0 1 8 12.25v-4.5a.75.75 0 0 1 .39-.658Z" clip-rule="evenodd"/>
                        </svg>
                    @else
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                </span>
                <h3 id="qi-pending-modal-title" class="text-sm font-semibold text-gray-900">{{ $headerLabel }}</h3>
            </div>
            <button type="button"
                    wire:click="closePending"
                    aria-label="Close pending job modal"
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-950/5 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                </svg>
            </button>
        </div>

        <div class="p-4">
            @if ($row === null)
                {{-- Race-after-pickup empty state. The row was open in the modal
                    but the next poll's hydration found nothing — a worker grabbed
                    the job (RecordJobProcessing deletes the pending hash), or
                    the TTL fired. Either way, there's nothing to render. --}}
                <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center">
                    <p class="text-sm font-medium text-gray-900">No longer pending</p>
                    <p class="mt-1 text-xs text-gray-500">
                        A worker has picked this job up, or the tracking entry expired. Close this modal and check the Recent completed / Recent failed lists for the result.
                    </p>
                </div>
            @else
                {{-- Identity hero — class FQCN + connection/queue chips, mirrors
                    the failed-modal layout. --}}
                <section class="mb-6">
                    <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500">{{ $headerHeroLabel }}</p>
                    @php
                        $heroChrome = match (true) {
                            $isInFlight => 'from-amber-50 ring-amber-600/10',
                            $isDelayed => 'from-indigo-50 ring-indigo-600/10',
                            default => 'from-gray-50 ring-gray-600/10',
                        };
                    @endphp
                    <div class="rounded-xl bg-linear-to-br to-white p-4 ring-1 ring-inset {{ $heroChrome }}">
                        <dl>
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Class</dt>
                            <dd class="mt-1 break-all font-mono text-sm font-medium text-gray-900">{{ $class ?? '—' }}</dd>
                        </dl>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                            <dl class="inline-flex items-center overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                                <dt class="bg-gray-950/[0.04] px-2 py-0.5 font-medium text-gray-500">Connection</dt>
                                <dd class="bg-white px-2 py-0.5 font-mono text-gray-800">{{ $connection ?? '—' }}</dd>
                            </dl>
                            <dl class="inline-flex items-center overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                                <dt class="bg-gray-950/[0.04] px-2 py-0.5 font-medium text-gray-500">Queue</dt>
                                <dd class="bg-white px-2 py-0.5 font-mono text-gray-800">{{ $queue ?? '—' }}</dd>
                            </dl>
                            @if ($batchId !== null)
                                @include('queue-insights::partials.batch-chip', ['batchId' => $batchId])
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Metrics row — waiting time + queued + state-specific tile.
                    In-flight gets `Started` + `Running for`; delayed gets
                    `Runs` (next available_at); pending gets the basic two. --}}
                @php
                    $tileCols = $isInFlight || $isDelayed ? 3 : 2;
                @endphp
                <dl @class([
                        'mb-3 grid gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5',
                        'grid-cols-2' => $tileCols === 2,
                        'grid-cols-3' => $tileCols === 3,
                    ])>
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Queued at</dt>
                        <dd class="mt-1">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $queuedCarbon?->diffForHumans() ?? '—' }}</p>
                            @if ($queuedCarbon)
                                <p class="truncate font-mono text-[10px] text-gray-400">{{ $queuedCarbon->toIso8601String() }}</p>
                            @endif
                        </dd>
                    </div>
                    @if ($isInFlight)
                        <div class="bg-white p-4">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Started</dt>
                            <dd class="mt-1">
                                <p class="truncate text-sm font-medium text-gray-900">{{ $startedCarbon?->diffForHumans() ?? '—' }}</p>
                                @if ($startedCarbon)
                                    <p class="truncate font-mono text-[10px] text-gray-400">{{ $startedCarbon->toIso8601String() }}</p>
                                @endif
                            </dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Running for</dt>
                            <dd class="mt-1 flex items-center gap-1.5">
                                <span aria-hidden="true" class="inline-block size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                                <span class="text-lg font-semibold tracking-tight tabular-nums text-gray-900">
                                    {{ $runningForHumanized ?? '—' }}
                                </span>
                            </dd>
                        </div>
                    @else
                        <div class="bg-white p-4">
                            <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Waiting for</dt>
                            <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-gray-900">
                                {{ $waitingForHumanized ?? '—' }}
                            </dd>
                        </div>
                        @if ($isDelayed)
                            <div class="bg-white p-4">
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Runs</dt>
                                <dd class="mt-1">
                                    <p class="truncate text-sm font-medium text-gray-900">{{ $availableCarbon?->diffForHumans() ?? '—' }}</p>
                                    @if ($availableCarbon)
                                        <p class="truncate font-mono text-[10px] text-gray-400">{{ $availableCarbon->toIso8601String() }}</p>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    @endif
                </dl>

                {{-- UUID — same identity row pattern as the failed modal --}}
                @if ($uuid !== null)
                    <dl class="flex items-center gap-2 border-t border-gray-950/5 pt-3">
                        <dt class="shrink-0 text-[10px] font-medium uppercase tracking-wider text-gray-400">UUID</dt>
                        <dd class="flex min-w-0 flex-1 items-center gap-1.5">
                            <code id="qi-pending-uuid"
                                  class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600">{{ $uuid }}</code>
                            <x-queue-insights::copy-button target="qi-pending-uuid" label="Copy UUID" variant="icon" class="shrink-0"/>
                        </dd>
                    </dl>
                @endif

                <p class="mt-4 text-[11px] text-gray-500">
                    @if ($isInFlight)
                        A worker is processing this job right now. The modal will flip to its empty state once the job finishes (success or failure) — check Recent completed / Recent failed afterwards.
                    @elseif ($isDelayed)
                        This job is scheduled to run later. It hasn't been picked up yet — the modal will flip to its empty state once a worker grabs it.
                    @else
                        This job is in line to run. It hasn't been picked up yet — the modal will flip to its empty state once a worker grabs it.
                    @endif
                </p>
            @endif
        </div>
    </div>
</div>
