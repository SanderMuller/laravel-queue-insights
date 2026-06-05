@props([
    /** @var array<string, mixed>|null Pending row from `QueueInsights::allPendingJobs()` / `allDelayedJobs()`. */
    'pending' => null,
    /** @var 'raw'|'json' Active payload tab — shared Livewire state with the completed- + failed-jobs modals. */
    'payloadTab' => 'raw',
    /** Currently-open batch id, '' if none. Drives the "Back to batch" button so the user can return to the batch view they came from. */
    'expandedBatchId' => '',
    /**
     * Top of the chain-navigation back stack, or null. Drives the
     * "Back to {class}" button.
     *
     * @var array{type: string, id: int|string, class: ?string}|null
     */
    'chainBackTop' => null,
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

    // Class FQCN title — namespace faded, leaf bold. Mirrors the
    // completed-jobs + failed-jobs modal titles.
    $classNs = '';
    $classLeaf = '';
    if (is_string($class) && $class !== '') {
        $lastBackslash = strrpos($class, '\\');
        $classNs = $lastBackslash !== false ? substr($class, 0, $lastBackslash + 1) : '';
        $classLeaf = $lastBackslash !== false ? substr($class, $lastBackslash + 1) : $class;
    }
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-pending-modal-title"
     x-data
     x-on:keydown.escape.window="$wire.closePending()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closePending">
    <div x-trap.noscroll="true"
         class="max-h-[88vh] w-full max-w-5xl overflow-auto rounded-2xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/10 dark:ring-white/10"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
            <div class="flex items-center gap-2">
                @if($expandedBatchId !== '')
                    <button type="button"
                            wire:click="closePending"
                            aria-label="Back to batch"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <x-queue-insights::icon-chevron-left class="size-3.5"/>
                        <span>Back to batch</span>
                    </button>
                @endif
                @include('queue-insights::partials.chain-back-button', ['frame' => $chainBackTop])
                <span class="inline-flex size-6 items-center justify-center rounded-md ring-1 ring-inset {{ match (true) {
                    $isInFlight => 'bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 ring-amber-600/20 dark:ring-amber-400/30',
                    $isDelayed => 'bg-indigo-50 text-indigo-600 ring-indigo-600/20',
                    default => 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 ring-gray-600/20',
                } }}">
                    @if($isInFlight)
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm6.39-2.908a.75.75 0 0 1 .766.027l3.5 2.25a.75.75 0 0 1 0 1.262l-3.5 2.25A.75.75 0 0 1 8 12.25v-4.5a.75.75 0 0 1 .39-.658Z" clip-rule="evenodd"/>
                        </svg>
                    @else
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                </span>
                <h3 id="qi-pending-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $headerLabel }}</h3>
            </div>
            <button type="button"
                    wire:click="closePending"
                    aria-label="Close pending job modal"
                    class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                <x-queue-insights::icon-close/>
            </button>
        </div>

        @if($row === null)
            {{-- Race-after-pickup empty state. The row was open in the modal
                but the next poll's hydration found nothing — a worker grabbed
                the job (RecordJobProcessing deletes the pending hash), or
                the TTL fired. Either way, there's nothing to render. --}}
            <div class="p-4">
                <div class="rounded-xl border border-dashed border-gray-950/10 p-6 text-center dark:border-white/10">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">No longer pending</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-300">
                        A worker has picked this job up, or the tracking entry expired. Close this modal and check the Recent completed / Recent failed lists for the result.
                    </p>
                </div>
            </div>
        @else
            @php
                // Hero gradient — retained as a PHP-string match so the
                // DarkModeModalsTest token pairs (amber / indigo / gray-800)
                // remain lint-visible, even though the gradient itself is
                // no longer rendered to a surface in the two-column layout.
                $heroChrome = match (true) {
                    $isInFlight => 'from-amber-50 ring-amber-600/10 dark:from-amber-900/40 dark:ring-amber-400/30',
                    $isDelayed => 'from-indigo-50 ring-indigo-600/10 dark:from-indigo-900/40 dark:ring-indigo-400/30',
                    default => 'from-gray-50 ring-gray-600/10 dark:from-gray-800 dark:ring-white/10',
                };
                $statusBadge = match (true) {
                    $isInFlight => ['cls' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-900/40 dark:text-amber-300 dark:ring-amber-400/30', 'dot' => 'bg-amber-500'],
                    $isDelayed => ['cls' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-900/40 dark:text-indigo-300 dark:ring-indigo-400/30', 'dot' => 'bg-indigo-500'],
                    default => ['cls' => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10', 'dot' => 'bg-gray-500'],
                };
            @endphp
            <div class="grid md:grid-cols-[22rem_1fr]">
                {{-- Left rail — identity, queue context, UUID, lineage,
                    optional batch teaser. Mirrors the failed/details modal
                    rail so cross-modal navigation feels consistent. --}}
                <div class="border-b border-gray-950/5 p-5 md:border-b-0 md:border-r dark:border-white/10">
                    {{-- Class FQCN as the modal title — namespace fades to a soft
                        secondary, base-class leaf bold. Matches the completed-
                        and failed-jobs modal titles. --}}
                    @if($classLeaf !== '')
                        <p class="mb-4 break-all font-mono text-sm">@if($classNs !== '')<span class="text-gray-400 dark:text-gray-500">{{ $classNs }}</span>@endif<span class="font-semibold text-gray-900 dark:text-gray-100">{{ $classLeaf }}</span></p>
                    @else
                        <p class="mb-4 font-mono text-sm text-gray-400 dark:text-gray-500">—</p>
                    @endif

                    <p class="mb-3 inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $statusBadge['cls'] }}">
                        <span aria-hidden="true" class="inline-block size-1.5 rounded-full {{ $statusBadge['dot'] }} {{ $isInFlight ? 'animate-pulse' : '' }}"></span>
                        {{ $headerHeroLabel }}
                    </p>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="Connection" :value="$connection"/>
                        <x-queue-insights::meta-pill label="Queue" :value="$queue"/>
                    </div>

                    <dl class="mt-4 divide-y divide-gray-950/5 border-t border-gray-950/5 text-xs dark:divide-white/10 dark:border-white/10">
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Queued at</dt>
                            <dd class="min-w-0 text-right">
                                <x-queue-insights::qi-time :at="$queuedCarbon" class="block truncate font-medium text-gray-900 dark:text-gray-100"/>
                                @if($queuedCarbon)
                                    <x-queue-insights::qi-time :at="$queuedCarbon" format="absolute-mono" class="block truncate text-[10px] text-gray-400 dark:text-gray-500"/>
                                @endif
                            </dd>
                        </div>
                        @if($isInFlight)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">Started</dt>
                                <dd class="min-w-0 text-right">
                                    <x-queue-insights::qi-time :at="$startedCarbon" class="block truncate font-medium text-gray-900 dark:text-gray-100"/>
                                    @if($startedCarbon)
                                        <x-queue-insights::qi-time :at="$startedCarbon" format="absolute-mono" class="block truncate text-[10px] text-gray-400 dark:text-gray-500"/>
                                    @endif
                                </dd>
                            </div>
                        @elseif($isDelayed)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">Runs at</dt>
                                <dd class="min-w-0 text-right">
                                    <x-queue-insights::qi-time :at="$availableCarbon" class="block truncate font-medium text-gray-900 dark:text-gray-100"/>
                                    @if($availableCarbon)
                                        <x-queue-insights::qi-time :at="$availableCarbon" format="absolute-mono" class="block truncate text-[10px] text-gray-400 dark:text-gray-500"/>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if($uuid !== null)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">UUID</dt>
                                <dd class="flex min-w-0 items-center gap-1.5">
                                    <code id="qi-pending-uuid"
                                          class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $uuid }}</code>
                                    <x-queue-insights::copy-button target="qi-pending-uuid" label="Copy UUID" variant="icon" class="shrink-0"/>
                                </dd>
                            </div>
                        @endif
                        @php
                            // Initiator — who started this job. Origin is the coarse
                            // entry point (http/artisan/schedule); call_site is the
                            // exact dispatch file:line. Both omitted when absent.
                            $pendingOrigin = is_string($row['origin'] ?? null) && $row['origin'] !== ''
                                ? $row['origin']
                                : null;
                            $pendingCallSite = is_string($row['call_site'] ?? null) && $row['call_site'] !== ''
                                ? $row['call_site']
                                : null;
                        @endphp
                        @if($pendingOrigin !== null)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">Origin</dt>
                                <dd class="min-w-0 break-all text-right font-mono text-[11px] text-gray-900 dark:text-gray-100">{{ $pendingOrigin }}</dd>
                            </div>
                        @endif
                        @if($pendingCallSite !== null)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">Dispatched from</dt>
                                <dd class="min-w-0 text-right">
                                    <code class="break-all rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $pendingCallSite }}</code>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @include('queue-insights::partials.parent-lineage-row', [
                        'parentUuid' => $row['parent_uuid'] ?? null,
                        'parentClass' => $row['parent_class'] ?? null,
                        'parentTarget' => $row['parent_target'] ?? null,
                        'fromClass' => $class,
                        'copyId' => 'qi-pending-parent-uuid',
                    ])

                    @if($batchId !== null)
                        @include('queue-insights::partials.batch-teaser', ['batchId' => $batchId])
                    @endif
                </div>

                {{-- Right column — live state hero + explanatory note. The
                    big number is the thing operators came here for: how long
                    has this been running / how long until it does. --}}
                <div class="min-w-0 space-y-4 p-5">
                    @if($isInFlight)
                        <div class="rounded-xl bg-amber-50/60 p-5 ring-1 ring-inset ring-amber-600/15 dark:bg-amber-900/20 dark:ring-amber-400/25">
                            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                <span aria-hidden="true" class="inline-block size-2 animate-pulse rounded-full bg-amber-500"></span>
                                Running for
                            </p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $runningForHumanized ?? '—' }}
                            </p>
                            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-300">elapsed since worker pickup</p>
                        </div>
                    @elseif($isDelayed)
                        <div class="rounded-xl bg-indigo-50/60 p-5 ring-1 ring-inset ring-indigo-600/15 dark:bg-indigo-900/20 dark:ring-indigo-400/25">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">Scheduled</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">
                                @if($availableCarbon)
                                    <x-queue-insights::qi-time :at="$availableCarbon"/>
                                @else
                                    —
                                @endif
                            </p>
                            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-300">waiting {{ $waitingForHumanized ?? '—' }} so far</p>
                        </div>
                    @else
                        <div class="rounded-xl bg-gray-50 p-5 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Waiting in queue</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $waitingForHumanized ?? '—' }}
                            </p>
                            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-300">since enqueue</p>
                        </div>
                    @endif

                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-300">
                        @if($isInFlight)
                            A worker is processing this job right now. The modal will flip to its empty state once the job finishes (success or failure) — check Recent completed / Recent failed afterwards.
                        @elseif($isDelayed)
                            This job is scheduled to run later. It hasn't been picked up yet — the modal will flip to its empty state once a worker grabs it.
                        @else
                            This job is in line to run. It hasn't been picked up yet — the modal will flip to its empty state once a worker grabs it.
                        @endif
                    </p>

                    {{-- Payload — populated when `pending.capture.payloads` is on
                        (off by default; see config docblock for the memory math).
                        Same `structured-payload` renderer the details-modal uses,
                        so a serialized `illuminate:log:context` entry expands
                        Sentry-style via `ValueParser`. --}}
                    @php
                        $pendingPayloadBody = $row['payload_body'] ?? null;
                        $pendingPayloadDecoded = is_string($pendingPayloadBody) && $pendingPayloadBody !== ''
                            ? (json_decode($pendingPayloadBody, true) ?? $pendingPayloadBody)
                            : null;
                        $pendingPayloadNote = ($row['payload_note'] ?? null) === 'payload_not_persisted';
                        $pendingPayloadEncErr = ($row['payload_error'] ?? null) === 'payload_encoding_failed';
                        $pendingPayloadSizeErr = ($row['payload_error'] ?? null) === 'payload_too_large';

                        // Decode the metadata-mode backoff field. Full-capture
                        // jobs carry backoff in the decoded body instead.
                        $pendingBackoffRaw = $row['payload_backoff'] ?? null;
                        $pendingBackoffDecoded = is_string($pendingBackoffRaw) && $pendingBackoffRaw !== ''
                            ? json_decode($pendingBackoffRaw, true)
                            : null;
                        $pendingBackoffIsList = is_array($pendingBackoffDecoded) && array_is_list($pendingBackoffDecoded);

                        // Body fed to the shared job-config-hero partial.
                        // Pending capture always writes job config as the narrow
                        // flat `payload_*` fields (both metadata AND full mode —
                        // `payload_body` is the separate serialized command), so
                        // the hero is built from those rather than the decoded
                        // body. `backoff` passes the decoded list when it was a
                        // JSON array so the partial joins it into one pill.
                        $heroBody = [];
                        if (isset($row['payload_maxTries']) && $row['payload_maxTries'] !== '') {
                            $heroBody['maxTries'] = $row['payload_maxTries'];
                        }
                        if (isset($row['payload_timeout']) && $row['payload_timeout'] !== '') {
                            $heroBody['timeout'] = $row['payload_timeout'];
                        }
                        if (is_string($pendingBackoffRaw) && $pendingBackoffRaw !== '') {
                            $heroBody['backoff'] = $pendingBackoffIsList ? $pendingBackoffDecoded : $pendingBackoffRaw;
                        }

                        // Structured-tab body — job-config keys + tags stripped so
                        // it stays job-payload-focused; those surface in the hero.
                        $heroBodyKeys = ['maxTries', 'maxExceptions', 'timeout', 'backoff', 'retryUntil', 'failOnTimeout', 'tags'];
                        $pendingPayloadFiltered = $pendingPayloadDecoded;
                        if (is_array($pendingPayloadFiltered)) {
                            foreach ($heroBodyKeys as $stripKey) {
                                unset($pendingPayloadFiltered[$stripKey]);
                            }
                        }
                    @endphp
                    @if($pendingPayloadNote)
                        <div class="flex gap-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/40 dark:text-amber-200 dark:ring-amber-400/30">
                            <x-queue-insights::icon-warning-triangle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"/>
                            <div class="min-w-0">
                                <p class="font-medium">Payload not persisted</p>
                                @if($reason = ($row['payload_reason'] ?? null))
                                    <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">Reason: {{ str_replace('_', ' ', $reason) }}</p>
                                @endif
                            </div>
                        </div>
                    @elseif($pendingPayloadEncErr)
                        <div class="flex gap-3 rounded-lg bg-red-50 p-3 text-sm text-red-900 ring-1 ring-inset ring-red-600/20 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-400/30">
                            <x-queue-insights::icon-error-circle class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400"/>
                            <p class="min-w-0">Payload encoding failed — sanitizer could not JSON-encode this job's payload.</p>
                        </div>
                    @elseif($pendingPayloadSizeErr)
                        <div class="flex gap-3 rounded-lg bg-red-50 p-3 text-sm text-red-900 ring-1 ring-inset ring-red-600/20 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-400/30">
                            <x-queue-insights::icon-error-circle class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400"/>
                            <div class="min-w-0">
                                <p class="font-medium">Payload exceeded size cap</p>
                                @if($size = ($row['payload_size'] ?? null))
                                    <p class="mt-1 text-xs tabular-nums text-red-800 dark:text-red-200">{{ $size }} bytes — raise
                                        <code class="rounded bg-red-100 px-1 font-mono dark:bg-red-900/60">pending.capture.max_payload_bytes</code>
                                        or narrow the sanitizer.
                                    </p>
                                @endif
                            </div>
                        </div>
                    @else
                        {{-- Job-config hero — shared partial, self-gates when there's
                            no config to show. No subtitle — class FQCN is the title. --}}
                        @include('queue-insights::partials.job-config-hero', ['body' => $heroBody, 'subtitle' => null])

                        @if($pendingPayloadDecoded !== null)
                            {{-- Payload — shared underline-tab partial. Structured
                                shows the config-stripped body; JSON keeps the full
                                sanitized payload for the colorizer. --}}
                            <section data-section="pending-payload">
                                @include('queue-insights::partials.payload-tabs', [
                                    'idPrefix' => 'qi-pending',
                                    'payloadTab' => $payloadTab,
                                    'structuredBody' => $pendingPayloadFiltered ?? $pendingPayloadDecoded,
                                    'jsonBody' => $pendingPayloadDecoded,
                                ])
                            </section>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
