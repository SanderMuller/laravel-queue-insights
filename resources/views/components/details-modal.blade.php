@props([
    /** @var array<string, mixed> Stream entry decoded by QueueInsights::recentCompleted. */
    'payload' => [],
    /** @var 'raw'|'json' Active payload tab. */
    'payloadTab' => 'raw',
    /** Capture mode (queue-insights.capture.payloads). Accepts the enum or the legacy raw string for backwards compat with hosts that override the props. */
    'captureMode' => \SanderMuller\QueueInsights\Enums\CaptureMode::Off,
    /** Currently-open batch id, '' if none. Drives the "Back to batch" button so the user can return to the batch view they came from. */
    'expandedBatchId' => '',
    /**
     * Top of the chain-navigation back stack, or null. Drives the
     * "Back to {class}" button so a user who navigated through `↰ From`
     * can return to the modal they came from.
     *
     * @var array{type: string, id: int|string, class: ?string}|null
     */
    'chainBackTop' => null,
])

@php
    // Accept either the CaptureMode enum (the production path from
    // DashboardData) or a raw string (a host that publishes this view
    // and writes their own prop value). Normalising up front keeps the
    // comparisons below typed against the enum.
    $captureMode = $captureMode instanceof \SanderMuller\QueueInsights\Enums\CaptureMode
        ? $captureMode
        : (\SanderMuller\QueueInsights\Enums\CaptureMode::tryFrom(is_string($captureMode) ? $captureMode : 'off') ?? \SanderMuller\QueueInsights\Enums\CaptureMode::Off);
    $sectionBKeys = ['payload_displayName', 'payload_maxTries', 'payload_timeout', 'payload_backoff', 'payload_note', 'payload_reason', 'payload_error', 'payload_size'];
    // Decoded-body keys that belong in the hero (Job Config card) — kept in
    // one place so the hasSectionB gate, the hero pill source map, and the
    // payload-tab filter all agree on what counts as "config".
    $heroBodyKeys = ['maxTries', 'maxExceptions', 'timeout', 'backoff', 'retryUntil', 'failOnTimeout', 'tags'];
    $sectionCBodyRaw = $payload['payload_body'] ?? null;
    $sectionCBody = is_string($sectionCBodyRaw) ? (json_decode($sectionCBodyRaw, true) ?? $sectionCBodyRaw) : null;
    // Hero renders whenever EITHER the narrow `payload_*` capture-mode
    // fields OR the decoded body carries hero data — otherwise full-capture
    // fixtures without the `payload_*` slice would silently hide the card.
    $hasSectionB = ! empty(array_intersect($sectionBKeys, array_keys($payload)))
        || (is_array($sectionCBody) && ! empty(array_intersect($heroBodyKeys, array_keys($sectionCBody))));

    // Duration — humanize with short form + keep raw ms in gray.
    // Sub-second durations fall through to a plain "{ms} ms" because
    // Carbon's cascade() rounds them down to "0s", which is misleading
    // when the actual duration is meaningful (e.g. 384 ms).
    $durationRaw = $payload['duration_ms'] ?? '';
    $durationMs = is_numeric($durationRaw) ? (int) $durationRaw : 0;
    $durationHumanized = $durationMs <= 0
        ? '—'
        : ($durationMs < 1000
            ? $durationMs . ' ms'
            : \Carbon\CarbonInterval::milliseconds($durationMs)->cascade()->forHumans(['short' => true]));

    // Wait — enqueue → worker pickup. Computed by RecordJobProcessing from
    // the pushed:{uuid} key. Empty string = no sample (legacy / driver path).
    $waitRaw = $payload['wait_ms'] ?? '';
    $waitMs = is_numeric($waitRaw) ? (int) $waitRaw : 0;
    $waitHumanized = $waitMs <= 0
        ? null
        : ($waitMs < 1000
            ? $waitMs . ' ms'
            : \Carbon\CarbonInterval::milliseconds($waitMs)->cascade()->forHumans(['short' => true]));

    $processedAtRaw = $payload['processed_at'] ?? null;

    $attemptsRaw = $payload['attempts'] ?? '';
    $attemptsInt = is_numeric($attemptsRaw) ? (int) $attemptsRaw : null;

    // Section B — decode backoff if present and looks like a JSON array.
    $backoffRaw = $payload['payload_backoff'] ?? null;
    $backoffDecoded = is_string($backoffRaw) && $backoffRaw !== '' ? json_decode($backoffRaw, true) : null;
    $backoffIsList = is_array($backoffDecoded) && array_is_list($backoffDecoded);

    // Forward chain — JSON-encoded list of `{class, connection, queue}` per
    // chained job, written by `RecordJobProcessed`. Decoded into the same
    // uniform `chain` shape used by the failed-modal.
    $chain = \SanderMuller\QueueInsights\Support\RowEnricher::decodeChain(
        is_string($payload['chain'] ?? null) ? $payload['chain'] : '',
    );

    $hasJobConfigCards = ! empty(array_intersect(['payload_displayName', 'payload_maxTries', 'payload_timeout', 'payload_backoff'], array_keys($payload)));
    $hasStatusNote = ($payload['payload_note'] ?? null) === 'payload_not_persisted';
    $hasStatusEncodingError = ($payload['payload_error'] ?? null) === 'payload_encoding_failed';
    $hasStatusSizeOverflow = ($payload['payload_error'] ?? null) === 'payload_too_large';
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-modal-title"
     x-data="{ view: 'job', chainIndex: 0 }"
     x-on:keydown.escape.window="
        if (view === 'chain-detail') { view = 'chain'; }
        else if (view === 'chain') { view = 'job'; }
        else { $wire.closePayload(); }
     "
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closePayload">
    <div x-trap.noscroll="true"
         class="max-h-[88vh] w-full max-w-5xl overflow-auto rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
         @click.stop>
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
            <div class="flex items-center gap-2 min-w-0">
                @if($expandedBatchId !== '')
                    <button type="button"
                            x-show="view === 'job'"
                            wire:click="closePayload"
                            aria-label="Back to batch"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <x-queue-insights::icon-chevron-left class="size-3.5"/>
                        <span>Back to batch</span>
                    </button>
                @endif
                @include('queue-insights::partials.chain-back-button', ['frame' => $chainBackTop])
                {{-- Back button only visible while inside the chain detail view. --}}
                <button type="button"
                        x-show="view === 'chain' || view === 'chain-detail'"
                        x-cloak
                        x-on:click="view = (view === 'chain-detail') ? 'chain' : 'job'"
                        aria-label="Back"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <x-queue-insights::icon-chevron-left class="size-3.5"/>
                    <span>Back</span>
                </button>
                <h3 id="qi-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    <span x-show="view === 'job'">Details</span>
                    <span x-show="view === 'chain'" x-cloak>Chained jobs</span>
                    <span x-show="view === 'chain-detail'" x-cloak>Chained job details</span>
                </h3>
            </div>

            <div class="flex items-center gap-3">
                <span x-show="view === 'job'" class="whitespace-nowrap rounded-md bg-gray-950/5 dark:bg-white/10 px-2 py-0.5 font-mono text-xs text-gray-700 dark:text-gray-300">capture: {{ $captureMode->value }}</span>
                <button type="button"
                        wire:click="closePayload"
                        aria-label="Close details modal"
                        class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <x-queue-insights::icon-close/>
                </button>
            </div>
        </div>

        <div x-show="view === 'job'">
            @php
                $payloadBatchId = is_string($payload['batch_id'] ?? null) && $payload['batch_id'] !== ''
                    ? $payload['batch_id']
                    : null;
                // Right column is empty only in full-capture mode with no job
                // config and no payload body — fall back to an explicit note.
                $hasRightContent = $hasSectionB
                    || $sectionCBody !== null
                    || $captureMode !== \SanderMuller\QueueInsights\Enums\CaptureMode::Full;
            @endphp
            @php
                $classFqcn = is_string($payload['class'] ?? null) && $payload['class'] !== ''
                    ? $payload['class']
                    : null;
                $classNs = '';
                $classLeaf = '';
                if ($classFqcn !== null) {
                    $lastBackslash = strrpos($classFqcn, '\\');
                    $classNs = $lastBackslash !== false ? substr($classFqcn, 0, $lastBackslash + 1) : '';
                    $classLeaf = $lastBackslash !== false ? substr($classFqcn, $lastBackslash + 1) : $classFqcn;
                }
            @endphp
            <div class="grid md:grid-cols-[22rem_1fr]">
                {{-- Left rail — class title + metadata description list. --}}
                <div data-section="base" class="border-b border-gray-950/5 p-5 md:border-b-0 md:border-r dark:border-white/10">
                    {{-- Class leaf as the modal title — namespace lives in the
                        hover/focus tooltip so the rail stays scannable for the
                        common case (everyone reads the leaf, the FQCN is only
                        useful when you actually need to copy / disambiguate). --}}
                    @if($classFqcn !== null)
                        <p class="mb-4 break-all font-mono text-sm">
                            <x-queue-insights::hint :trigger-class="'break-all font-semibold text-gray-900 dark:text-gray-100'">
                                {{ $classLeaf }}
                                <x-slot:tip>
                                    <span class="block break-all font-mono">{{ $classFqcn }}</span>
                                </x-slot:tip>
                            </x-queue-insights::hint>
                        </p>
                    @else
                        <p class="mb-4 font-mono text-sm text-gray-400 dark:text-gray-500">—</p>
                    @endif

                    <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Metadata</p>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="Connection" :value="$payload['connection'] ?? null"/>
                        <x-queue-insights::meta-pill label="Queue" :value="$payload['queue'] ?? null"/>
                    </div>

                    <dl class="mt-4 divide-y divide-gray-950/5 border-t border-gray-950/5 text-xs dark:divide-white/10 dark:border-white/10">
                        <div class="flex items-baseline justify-between gap-3 py-1.5">
                            <dt class="text-gray-500 dark:text-gray-400">Duration</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $durationHumanized }}
                                @if($durationMs >= 1000)
                                    <span class="text-gray-400 dark:text-gray-500">({{ $durationMs }} ms)</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5" title="Wait time = enqueue → worker pickup">
                            <dt class="text-gray-500 dark:text-gray-400">Wait</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $waitHumanized ?? '—' }}
                                @if($waitMs >= 1000)
                                    <span class="text-gray-400 dark:text-gray-500">({{ $waitMs }} ms)</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5">
                            <dt class="text-gray-500 dark:text-gray-400">Attempts</dt>
                            <dd class="text-right">
                                @if($attemptsInt === null)
                                    <span class="font-medium text-gray-400 dark:text-gray-500">—</span>
                                @else
                                    <span class="font-medium tabular-nums {{ $attemptsInt > 1 ? 'rounded bg-amber-100 px-1.5 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200' : 'text-gray-900 dark:text-gray-100' }}">{{ $attemptsInt }}</span>
                                    @if($attemptsInt > 1)
                                        <span class="ml-1 text-[10px] font-medium uppercase tracking-wider text-amber-700 dark:text-amber-300">retry</span>
                                    @endif
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Processed at</dt>
                            <dd class="min-w-0 text-right">
                                <x-queue-insights::qi-time :at="$processedAtRaw" class="block truncate font-medium text-gray-900 dark:text-gray-100"/>
                                @if($processedAtRaw)
                                    <x-queue-insights::qi-time :at="$processedAtRaw" format="absolute-mono" class="block truncate text-[10px] text-gray-400 dark:text-gray-500"/>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Stream ID</dt>
                            <dd class="flex min-w-0 items-center gap-1.5">
                                <code id="qi-stream-id"
                                      class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $payload['_id'] ?? '—' }}</code>
                                <x-queue-insights::copy-button target="qi-stream-id" label="Copy stream id" variant="icon" class="shrink-0"/>
                            </dd>
                        </div>
                    </dl>

                    @include('queue-insights::partials.parent-lineage-row', [
                        'parentUuid' => $payload['parent_uuid'] ?? null,
                        'parentClass' => $payload['parent_class'] ?? null,
                        'parentTarget' => $payload['parent_target'] ?? null,
                        'fromClass' => is_string($payload['class'] ?? null) ? $payload['class'] : null,
                        'copyId' => 'qi-completed-parent-uuid',
                    ])

                    @if($payloadBatchId !== null)
                        @include('queue-insights::partials.batch-teaser', ['batchId' => $payloadBatchId])
                    @endif

                    @if($chain !== null)
                        <button type="button"
                                data-section="chain"
                                x-on:click="view = 'chain'"
                                class="mt-3 block w-full rounded-lg p-3 text-left ring-1 ring-gray-950/5 transition hover:bg-gray-50 hover:ring-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:ring-white/10 dark:hover:bg-gray-800"
                                aria-label="View full chain details">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Chain</p>
                                <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">View {{ $chain['remaining'] }} chained {{ $chain['remaining'] === 1 ? 'job' : 'jobs' }} →</span>
                            </div>
                            <p class="mt-1 break-all font-mono text-xs text-gray-900 dark:text-gray-100">{{ $chain['next_class'] }}</p>
                            @if($chain['remaining'] > 1)
                                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-300">+{{ $chain['remaining'] - 1 }} more chained</p>
                            @endif
                        </button>
                    @endif
                </div>

                {{-- Right column — job config + payload body. --}}
                <div class="min-w-0 space-y-6 p-5">
                    @php
                        $displayName = $payload['payload_displayName'] ?? null;
                        // Status-branch banners (closure / encoding / size) keep the same
                        // markup as before — alert states, not data layout.
                        $statusBranch = null;
                        if ($hasSectionB) {
                            if ($hasStatusNote) {
                                $statusBranch = 'note';
                            } elseif ($hasStatusEncodingError) {
                                $statusBranch = 'encoding';
                            } elseif ($hasStatusSizeOverflow) {
                                $statusBranch = 'size';
                            }
                        }

                        // Body fed to the shared job-config-hero partial. Full-capture
                        // hands it the decoded payload directly; metadata mode (no
                        // `payload_body`) synthesises a body-shaped array from the
                        // narrow `payload_*` capture fields so the hero renders
                        // identically. `backoff` is passed as the decoded list when it
                        // was a JSON array so the partial joins it into one pill.
                        if (is_array($sectionCBody)) {
                            $heroBody = $sectionCBody;
                        } else {
                            $heroBody = [];
                            if (isset($payload['payload_maxTries']) && $payload['payload_maxTries'] !== '') {
                                $heroBody['maxTries'] = $payload['payload_maxTries'];
                            }
                            if (isset($payload['payload_timeout']) && $payload['payload_timeout'] !== '') {
                                $heroBody['timeout'] = $payload['payload_timeout'];
                            }
                            if (is_string($backoffRaw) && $backoffRaw !== '') {
                                $heroBody['backoff'] = $backoffIsList ? $backoffDecoded : $backoffRaw;
                            }
                        }

                        // Body passed to the payload tabs — config keys + tags stripped
                        // so the Structured render stays focused on job-specific
                        // payload data. Keys outside this list (uuid, data, displayName,
                        // attempts, etc.) flow through unchanged.
                        $sectionCBodyFiltered = $sectionCBody;
                        if (is_array($sectionCBodyFiltered)) {
                            foreach ($heroBodyKeys as $stripKey) {
                                unset($sectionCBodyFiltered[$stripKey]);
                            }
                        }
                    @endphp

                    {{-- Job-config section. Status-branch banners replace the hero
                        when the payload couldn't be captured; otherwise the shared
                        job-config-hero partial renders. The class FQCN lives in the
                        left rail as the modal title, so the hero only carries the
                        displayName subtitle when it diverges from the class. --}}
                    @if($hasSectionB)
                        <section data-section="job-config">
                            @if($statusBranch === 'note')
                                @include('queue-insights::partials.details-modal-status-note', ['reason' => $payload['payload_reason'] ?? null])
                            @elseif($statusBranch === 'encoding')
                                @include('queue-insights::partials.details-modal-status-encoding')
                            @elseif($statusBranch === 'size')
                                @include('queue-insights::partials.details-modal-status-size', ['size' => $payload['payload_size'] ?? null])
                            @else
                                @include('queue-insights::partials.job-config-hero', [
                                    'body' => $heroBody,
                                    'subtitle' => ($displayName !== null && $displayName !== $classFqcn) ? $displayName : null,
                                ])
                            @endif
                        </section>
                    @endif

                    {{-- Payload section. Structured tab uses the FILTERED body so
                         config + tags don't double up with the hero chips; JSON tab
                         keeps the ORIGINAL body so the operator can still inspect
                         maxTries / retryUntil / tags as captured. --}}
                    @if($sectionCBody !== null)
                        <section data-section="payload">
                            @include('queue-insights::partials.payload-tabs', [
                                'idPrefix' => 'qi',
                                'payloadTab' => $payloadTab,
                                'structuredBody' => $sectionCBodyFiltered,
                                'jsonBody' => $sectionCBody,
                            ])
                        </section>
                    @endif

                    {{-- Footer — tiered escalation hints. Shared across all variants. --}}
                    @if($captureMode === \SanderMuller\QueueInsights\Enums\CaptureMode::Off)
                        <div class="flex gap-3 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-xs leading-5 text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
                            <x-queue-insights::icon-info-circle class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-400"/>
                            <p>
                                Capture is off — only base metadata is stored. Set
                                <code class="rounded bg-white dark:bg-gray-900 px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">QUEUE_INSIGHTS_CAPTURE_PAYLOADS=metadata</code>
                                or
                                <code class="rounded bg-white dark:bg-gray-900 px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">=full</code>
                                to see more. Review
                                <code class="rounded bg-white dark:bg-gray-900 px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">SECURITY.md</code>
                                before enabling full.
                            </p>
                        </div>
                    @elseif($captureMode === \SanderMuller\QueueInsights\Enums\CaptureMode::Metadata)
                        <div class="flex gap-3 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-xs leading-5 text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
                            <x-queue-insights::icon-info-circle class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-400"/>
                            <p>
                                Metadata-only capture — job config without a serialized command body. Set
                                <code class="rounded bg-white dark:bg-gray-900 px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">=full</code>
                                (with a sanitizer) for sanitized payload bodies.
                            </p>
                        </div>
                    @endif

                    @unless($hasRightContent)
                        <div class="rounded-lg bg-gray-50 px-4 py-6 text-center text-xs text-gray-500 ring-1 ring-inset ring-gray-950/5 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10">
                            No job config or payload body was captured for this job.
                        </div>
                    @endunless
                </div>{{-- /right column --}}
            </div>{{-- /grid --}}
        </div>{{-- /job view --}}

        @if($chain !== null)
            @php
                $chainCap = 50;
                $chainJobs = array_slice($chain['jobs'], 0, $chainCap);
                $chainTotal = count($chain['jobs']);
                $chainTruncated = $chainTotal > $chainCap;
            @endphp
            <div class="p-4" x-show="view === 'chain'" x-cloak data-section="chain-detail">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Chain ({{ $chain['remaining'] }} {{ $chain['remaining'] === 1 ? 'job' : 'jobs' }} after this one)</p>
                <ol class="overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach($chainJobs as $i => $job)
                        <li>
                            <button type="button"
                                    x-on:click="chainIndex = {{ $i }}; view = 'chain-detail'"
                                    aria-label="View details for chained job {{ $i + 1 }}"
                                    class="flex w-full items-start gap-3 bg-white dark:bg-gray-900 p-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800 focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500">
                                <span aria-hidden="true" class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-950/[0.04] text-[11px] font-semibold tabular-nums text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">{{ $i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ $job['class'] }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                        <x-queue-insights::meta-pill label="Connection" :value="$job['connection'] ?? null" size="sm"/>
                                        <x-queue-insights::meta-pill label="Queue" :value="$job['queue'] ?? null" size="sm"/>
                                        @if($i === 0)
                                            <span class="rounded-md bg-gray-950/[0.04] dark:bg-white/10 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10" title="First link queued after the parent completed">queued next</span>
                                        @endif
                                    </div>
                                </div>
                                <x-queue-insights::icon-chevron-right class="mt-1 size-3 shrink-0 text-gray-400 dark:text-gray-400"/>
                            </button>
                        </li>
                    @endforeach
                </ol>
                @if($chainTruncated)
                    <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-300">
                        Showing the first {{ $chainCap }} of {{ $chainTotal }} chained jobs. The remaining {{ $chainTotal - $chainCap }} are hidden to keep the modal responsive.
                    </p>
                @endif
                <p class="mt-3 text-[11px] text-gray-500 dark:text-gray-300">
                    Chain context is a snapshot of what was serialized when the parent ran — it does not reflect whether the downstream links have since executed, failed, or are still pending. To see live state, search the Completed or Failed tab for the chained class. The next link's own connection/queue overrides the parent chain defaults when set.
                </p>
            </div>

            {{-- Chain item drill-down. Same shape as failed-modal — Alpine
                client-side swap on `chainIndex` keeps this a zero-round-trip
                interaction once the modal is open. Capped to the same
                window as the list above. --}}
            <div class="p-4" x-show="view === 'chain-detail'" x-cloak>
                @foreach($chainJobs as $i => $job)
                    <div x-show="chainIndex === {{ $i }}" x-cloak>
                        <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                            Chained job {{ $i + 1 }} of {{ $chainTotal }}
                            @if($i === 0)<span class="ml-1 rounded-md bg-gray-950/[0.04] dark:bg-white/10 px-1.5 py-0.5 font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">queued next</span>@endif
                        </p>
                        <div class="rounded-xl bg-gradient-to-br from-gray-50 to-white p-4 ring-1 ring-inset ring-gray-950/10 dark:from-gray-800 dark:to-gray-900 dark:ring-white/10">
                            <dl>
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Class</dt>
                                <dd class="mt-1 break-all font-mono text-sm font-medium text-gray-900 dark:text-gray-100">{{ $job['class'] }}</dd>
                            </dl>
                            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                                <x-queue-insights::meta-pill label="Connection" :value="$job['connection'] ?? null"/>
                                <x-queue-insights::meta-pill label="Queue" :value="$job['queue'] ?? null"/>
                            </div>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-gray-950/5 dark:bg-white/10 ring-1 ring-gray-950/5 dark:ring-white/10">
                            <div class="bg-white dark:bg-gray-900 p-4">
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Position</dt>
                                <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ $i + 1 }} <span class="text-xs tabular-nums text-gray-400 dark:text-gray-400">of {{ $chainTotal }}</span>
                                </dd>
                            </div>
                            <div class="bg-white dark:bg-gray-900 p-4">
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Live status</dt>
                                <dd class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300" title="The completed-stream entry stores chain routing only, not downstream run state — search the Completed or Failed tab by class to see actual runs.">
                                    <span aria-hidden="true" class="inline-block size-1.5 rounded-full bg-gray-400"></span>
                                    not tracked
                                </dd>
                            </div>
                        </dl>

                        {{-- Per-chained-job properties — only available when the
                            parent job's serialized command was retained (failed
                            jobs carry it in failed_jobs.payload). Completed jobs
                            persist only the slim chain summary on the stream
                            entry, so this section is empty here. --}}
                        @php
                            $chainProps = is_array($job['properties'] ?? null) ? $job['properties'] : [];
                        @endphp
                        @if(count($chainProps) > 0)
                            <div class="mt-3 rounded-lg bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10">
                                <p class="border-b border-gray-950/5 dark:border-white/10 px-4 py-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Job instance</p>
                                <x-queue-insights::serialized-properties :properties="$chainProps"/>
                            </div>
                        @else
                            <div class="mt-3 rounded-lg bg-gray-50 dark:bg-gray-800 px-4 py-3 text-[11px] text-gray-500 dark:text-gray-300 ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
                                Constructor data for chained jobs isn't retained on the completed stream — only routing context (class · connection · queue). Re-run the chain and inspect the failed-job modal for full property visibility.
                            </div>
                        @endif

                        <p class="mt-4 text-[11px] text-gray-500 dark:text-gray-300">
                            @if($i === 0)
                                This was the first link Laravel queued after the parent completed. Whether it has since succeeded, failed, or is still pending isn't tracked on the parent's stream entry — open the Completed or Failed tab and filter by class to see the actual run.
                            @else
                                This was queued after job {{ $i }} ({{ $chain['jobs'][$i - 1]['class'] }}) finished. The parent's stream entry only records routing context — current run state lives on the Completed / Failed tabs.
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
