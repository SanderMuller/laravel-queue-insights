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
    $hasSectionB = ! empty(array_intersect($sectionBKeys, array_keys($payload)));
    $sectionCBodyRaw = $payload['payload_body'] ?? null;
    $sectionCBody = is_string($sectionCBodyRaw) ? (json_decode($sectionCBodyRaw, true) ?? $sectionCBodyRaw) : null;

    // Duration — humanize with short form + keep raw ms in gray.
    $durationRaw = $payload['duration_ms'] ?? '';
    $durationHumanized = is_numeric($durationRaw) && (int) $durationRaw > 0
        ? \Carbon\CarbonInterval::milliseconds((int) $durationRaw)->cascade()->forHumans(['short' => true])
        : '—';

    // Wait — enqueue → worker pickup. Computed by RecordJobProcessing from
    // the pushed:{uuid} key. Empty string = no sample (legacy / driver path).
    $waitRaw = $payload['wait_ms'] ?? '';
    $waitHumanized = is_numeric($waitRaw) && (int) $waitRaw > 0
        ? \Carbon\CarbonInterval::milliseconds((int) $waitRaw)->cascade()->forHumans(['short' => true])
        : null;

    $processedAtRaw = $payload['processed_at'] ?? null;

    $attemptsRaw = $payload['attempts'] ?? '';
    $attemptsInt = is_numeric($attemptsRaw) ? (int) $attemptsRaw : null;

    // Section B — decode backoff if present and looks like a JSON array.
    $backoffRaw = $payload['payload_backoff'] ?? null;
    $backoffDisplay = null;
    if (is_string($backoffRaw) && $backoffRaw !== '') {
        $backoffDecoded = json_decode($backoffRaw, true);
        if (is_array($backoffDecoded) && array_is_list($backoffDecoded)) {
            $backoffDisplay = implode(', ', array_map(fn ($v): string => (string) $v, $backoffDecoded)).'s';
        } else {
            $backoffDisplay = $backoffRaw;
        }
    }

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
         class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 [--padding:--spacing(6)]"
         @click.stop>
        <div class="sticky top-0 px-4 flex items-center justify-between gap-3 border-b border-gray-950/5 bg-white px-4 py-4">
            <div class="flex items-center gap-2 min-w-0">
                @if($expandedBatchId !== '')
                    <button type="button"
                            x-show="view === 'job'"
                            wire:click="closePayload"
                            aria-label="Back to batch"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/>
                        </svg>
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
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/>
                    </svg>
                    <span>Back</span>
                </button>
                <h3 id="qi-modal-title" class="text-sm font-semibold text-gray-900">
                    <span x-show="view === 'job'">Details</span>
                    <span x-show="view === 'chain'" x-cloak>Chained jobs</span>
                    <span x-show="view === 'chain-detail'" x-cloak>Chained job details</span>
                </h3>
            </div>

            <div class="flex items-center gap-3">
                <span x-show="view === 'job'" class="whitespace-nowrap rounded-md bg-gray-950/5 px-2 py-0.5 font-mono text-xs text-gray-700">capture: {{ $captureMode->value }}</span>
                <button type="button"
                        wire:click="closePayload"
                        aria-label="Close details modal"
                        class="rounded-md p-1 text-gray-400 hover:bg-gray-950/5 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="p-4" x-show="view === 'job'">

            {{-- Section A: Base metadata — hierarchical structure:
                identity hero (class + connection + queue) → metrics row (duration / attempts / processed) → stream id. --}}
            <section data-section="base" class="mb-6">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500">Metadata</p>

                {{-- Identity hero: what job is this. --}}
                <div class="rounded-xl bg-linear-to-br from-gray-50 to-white p-4 ring-1 ring-gray-950/5">
                    <dl>
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Class</dt>
                        <dd class="mt-1 break-all font-mono text-sm font-medium text-gray-900">{{ $payload['class'] ?? '—' }}</dd>
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="Connection" :value="$payload['connection'] ?? null"/>
                        <x-queue-insights::meta-pill label="Queue" :value="$payload['queue'] ?? null"/>
                        @php
                            $payloadBatchId = is_string($payload['batch_id'] ?? null) && $payload['batch_id'] !== ''
                                ? $payload['batch_id']
                                : null;
                        @endphp
                        @if($payloadBatchId !== null)
                            @include('queue-insights::partials.batch-chip', ['batchId' => $payloadBatchId])
                        @endif
                    </div>
                </div>

                @include('queue-insights::partials.parent-lineage-row', [
                    'parentUuid' => $payload['parent_uuid'] ?? null,
                    'parentClass' => $payload['parent_class'] ?? null,
                    'parentTarget' => $payload['parent_target'] ?? null,
                    'fromClass' => is_string($payload['class'] ?? null) ? $payload['class'] : null,
                    'copyId' => 'qi-completed-parent-uuid',
                ])

                @if($chain !== null)
                    <button type="button"
                            data-section="chain"
                            x-on:click="view = 'chain'"
                            class="mt-3 block w-full text-left rounded-xl bg-white p-4 ring-1 ring-gray-950/5 transition hover:bg-gray-50 hover:ring-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                            aria-label="View full chain details">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500">Chain</p>
                            <span class="text-[10px] font-medium text-emerald-700">View {{ $chain['remaining'] }} chained {{ $chain['remaining'] === 1 ? 'job' : 'jobs' }} →</span>
                        </div>
                        <dl class="mt-2 space-y-1 text-xs">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <dt class="text-gray-400">Next</dt>
                                <dd class="break-all font-mono text-gray-900">{{ $chain['next_class'] }}</dd>
                                @if($chain['remaining'] > 1)
                                    <dd class="text-gray-500">(+{{ $chain['remaining'] - 1 }} more chained)</dd>
                                @endif
                            </div>
                            @if($chain['chain_queue'] !== null || $chain['chain_connection'] !== null)
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <dt class="text-gray-400">Queue</dt>
                                    <dd class="font-mono text-gray-700">{{ $chain['chain_queue'] ?? '—' }}</dd>
                                    @if($chain['chain_connection'] !== null)
                                        <dd class="text-gray-400">·</dd>
                                        <dd class="font-mono text-gray-700">{{ $chain['chain_connection'] }}</dd>
                                    @endif
                                </div>
                            @endif
                        </dl>
                    </button>
                @endif

                {{-- Metrics row: how it ran. --}}
                <dl class="mt-3 grid grid-cols-3 gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5">
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Duration</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-lg font-semibold tracking-tight text-gray-900 tabular-nums">{{ $durationHumanized }}</span>
                            @if(is_numeric($durationRaw) && (int) $durationRaw > 0)
                                <span class="text-xs tabular-nums text-gray-400">({{ (int) $durationRaw }} ms)</span>
                            @endif
                        </dd>
                        {{-- Wait time — enqueue → worker pickup. Renders `—` when no
                            sample exists (legacy job, custom driver, or queued before
                            the JobQueued listener was wired). --}}
                        <dd class="mt-1 text-[11px] tabular-nums text-gray-500"
                            title="Wait time = enqueue → worker pickup">
                            <span class="text-gray-400">wait</span>
                            <span class="font-medium text-gray-700">{{ $waitHumanized ?? '—' }}</span>
                            @if(is_numeric($waitRaw) && (int) $waitRaw > 0)
                                <span class="text-gray-400">({{ (int) $waitRaw }} ms)</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Attempts</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            @if($attemptsInt === null)
                                <span class="text-lg font-semibold tracking-tight text-gray-400">—</span>
                            @else
                                <span class="text-lg font-semibold tracking-tight tabular-nums {{ $attemptsInt > 1 ? 'bg-amber-100 text-amber-800 rounded px-2 py-0.5' : 'text-gray-900' }}">{{ $attemptsInt }}</span>
                                @if($attemptsInt > 1)
                                    <span class="text-[10px] font-medium uppercase tracking-wider text-amber-700">retry</span>
                                @endif
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Processed at</dt>
                        <dd class="mt-1">
                            <x-queue-insights::qi-time :at="$processedAtRaw" class="block truncate text-sm font-medium text-gray-900"/>
                            @if($processedAtRaw)
                                <x-queue-insights::qi-time :at="$processedAtRaw" format="absolute-mono" class="block truncate text-[10px] text-gray-400"/>
                            @endif
                        </dd>
                    </div>
                </dl>

                {{-- Stream ID — de-emphasized, bottom row. --}}
                <dl class="mt-3 flex items-center gap-2 border-t border-gray-950/5 pt-3">
                    <dt class="shrink-0 text-[10px] font-medium uppercase tracking-wider text-gray-400">Stream ID</dt>
                    <dd class="flex min-w-0 flex-1 items-center gap-1.5">
                        <code id="qi-stream-id"
                              class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600">{{ $payload['_id'] ?? '—' }}</code>
                        <x-queue-insights::copy-button target="qi-stream-id" label="Copy stream id" variant="icon" class="shrink-0"/>
                    </dd>
                </dl>
            </section>

            {{-- Section B: Job config (happy path) OR status branches (closure/error/overflow). --}}
            @if($hasSectionB)
                <section data-section="job-config" class="mb-6">
                    @if($hasStatusNote)
                        <div class="flex gap-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20">
                            <svg class="mt-0.5 size-4 shrink-0 text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                      d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                                      clip-rule="evenodd"/>
                            </svg>
                            <div class="min-w-0">
                                <p class="font-medium">Payload not persisted</p>
                                @if($reason = $payload['payload_reason'] ?? null)
                                    <p class="mt-1 text-xs text-amber-800">Reason: {{ str_replace('_', ' ', $reason) }}</p>
                                @endif
                            </div>
                        </div>
                    @elseif($hasStatusEncodingError)
                        <div class="flex gap-3 rounded-lg bg-red-50 p-3 text-sm text-red-900 ring-1 ring-inset ring-red-600/20">
                            <svg class="mt-0.5 size-4 shrink-0 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                      d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"
                                      clip-rule="evenodd"/>
                            </svg>
                            <div class="min-w-0">
                                <p class="font-medium">Payload encoding failed</p>
                                <p class="mt-1 text-xs text-red-800">Sanitizer could not JSON-encode the payload for this job.</p>
                            </div>
                        </div>
                    @elseif($hasStatusSizeOverflow)
                        <div class="flex gap-3 rounded-lg bg-red-50 p-3 text-sm text-red-900 ring-1 ring-inset ring-red-600/20">
                            <svg class="mt-0.5 size-4 shrink-0 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                      d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"
                                      clip-rule="evenodd"/>
                            </svg>
                            <div class="min-w-0">
                                <p class="font-medium">Payload exceeded size cap</p>
                                @if($size = $payload['payload_size'] ?? null)
                                    <p class="mt-1 text-xs text-red-800 tabular-nums">{{ $size }} bytes — raise
                                        <code class="rounded bg-red-100 px-1 font-mono">capture.max_payload_bytes</code>
                                        or narrow the sanitizer.
                                    </p>
                                @endif
                            </div>
                        </div>
                    @elseif($hasJobConfigCards)
                        <h4 class="mb-3 text-xs font-medium text-gray-500">Job Config</h4>

                        @if($displayName = $payload['payload_displayName'] ?? null)
                            <p class="mb-3 break-all font-mono text-xs text-gray-800">{{ $displayName }}</p>
                        @endif

                        <dl class="grid grid-cols-3 overflow-hidden rounded-lg ring-1 ring-gray-950/5">
                            @if(isset($payload['payload_maxTries']))
                                <div class="bg-white p-3 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-gray-950/5">
                                    <dt class="text-xs font-medium text-gray-500">maxTries</dt>
                                    <dd class="mt-1 text-base font-medium text-gray-900 tabular-nums">{{ $payload['payload_maxTries'] ?: '—' }}</dd>
                                </div>
                            @endif

                            @if(isset($payload['payload_timeout']))
                                <div class="bg-white p-3 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-gray-950/5">
                                    <dt class="text-xs font-medium text-gray-500">timeout</dt>
                                    <dd class="mt-1 text-base font-medium text-gray-900 tabular-nums">{{ $payload['payload_timeout'] ?: '—' }}</dd>
                                </div>
                            @endif

                            @if($backoffDisplay !== null)
                                <div class="bg-white p-3 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-gray-950/5">
                                    <dt class="text-xs font-medium text-gray-500">backoff</dt>
                                    <dd class="mt-1 text-base font-medium text-gray-900 tabular-nums">{{ $backoffDisplay }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </section>
            @endif

            {{-- Section C: Payload body (visible only when `payload_body` present). --}}
            @if($sectionCBody !== null)
                <section data-section="payload" class="mb-6 px-4">
                    <h4 class="mb-3 text-xs font-medium text-gray-500">Payload</h4>

                    <div class="mb-3 inline-flex rounded-md bg-gray-950/5 p-0.5" role="tablist">
                        <button type="button"
                                role="tab"
                                id="qi-tab-raw"
                                aria-selected="{{ $payloadTab === 'raw' ? 'true' : 'false' }}"
                                aria-controls="qi-panel-raw"
                                wire:click="setPayloadTab('raw')"
                                class="rounded px-3 py-1 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 {{ $payloadTab === 'raw' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                            Raw fields
                        </button>
                        <button type="button"
                                role="tab"
                                id="qi-tab-json"
                                aria-selected="{{ $payloadTab === 'json' ? 'true' : 'false' }}"
                                aria-controls="qi-panel-json"
                                wire:click="setPayloadTab('json')"
                                class="rounded px-3 py-1 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 {{ $payloadTab === 'json' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                            Sanitized JSON
                        </button>
                    </div>
                    @if($payloadTab === 'json')
                        <pre role="tabpanel"
                             id="qi-panel-json"
                             aria-labelledby="qi-tab-json"
                             data-json-highlight
                             class="whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-4 font-mono text-xs leading-5 text-gray-900 ring-1 ring-inset ring-gray-950/10">{{ is_array($sectionCBody) ? json_encode($sectionCBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $sectionCBody }}</pre>
                    @else
                        <div role="tabpanel"
                             id="qi-panel-raw"
                             aria-labelledby="qi-tab-raw">
                            <x-queue-insights::structured-payload :payload="$sectionCBody"/>
                        </div>
                    @endif
                </section>
            @endif

            {{-- Footer — tiered escalation hints. --}}
            @if($captureMode === \SanderMuller\QueueInsights\Enums\CaptureMode::Off)
                <div class="mt-6 flex gap-3 rounded-lg bg-gray-50 p-3 text-xs leading-5 text-gray-600 ring-1 ring-inset ring-gray-950/10">
                    <svg class="mt-0.5 size-4 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-3.75a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0V7a.75.75 0 0 1 .75-.75ZM10 15a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                              clip-rule="evenodd"/>
                    </svg>
                    <p>
                        Capture is off — only base metadata is stored. Set
                        <code class="rounded bg-white px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10">QUEUE_INSIGHTS_CAPTURE_PAYLOADS=metadata</code>
                        or
                        <code class="rounded bg-white px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10">=full</code>
                        to see more. Review
                        <code class="rounded bg-white px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10">SECURITY.md</code>
                        before enabling full.
                    </p>
                </div>
            @elseif($captureMode === \SanderMuller\QueueInsights\Enums\CaptureMode::Metadata)
                <div class="mt-6 flex gap-3 rounded-lg bg-gray-50 p-3 text-xs leading-5 text-gray-600 ring-1 ring-inset ring-gray-950/10">
                    <svg class="mt-0.5 size-4 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-3.75a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0V7a.75.75 0 0 1 .75-.75ZM10 15a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                              clip-rule="evenodd"/>
                    </svg>
                    <p>
                        Metadata-only capture — job config without a serialized command body. Set
                        <code class="rounded bg-white px-1 py-0.5 font-mono ring-1 ring-inset ring-gray-950/10">=full</code>
                        (with a sanitizer) for sanitized payload bodies.
                    </p>
                </div>
            @endif
        </div>{{-- /padding wrapper --}}

        @if($chain !== null)
            @php
                $chainCap = 50;
                $chainJobs = array_slice($chain['jobs'], 0, $chainCap);
                $chainTotal = count($chain['jobs']);
                $chainTruncated = $chainTotal > $chainCap;
            @endphp
            <div class="p-4" x-show="view === 'chain'" x-cloak data-section="chain-detail">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500">Chain ({{ $chain['remaining'] }} {{ $chain['remaining'] === 1 ? 'job' : 'jobs' }} after this one)</p>
                <ol class="overflow-hidden rounded-xl ring-1 ring-gray-950/5 divide-y divide-gray-950/5">
                    @foreach($chainJobs as $i => $job)
                        <li>
                            <button type="button"
                                    x-on:click="chainIndex = {{ $i }}; view = 'chain-detail'"
                                    aria-label="View details for chained job {{ $i + 1 }}"
                                    class="flex w-full items-start gap-3 bg-white p-4 text-left transition hover:bg-gray-50 focus-visible:bg-emerald-50/40 focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-emerald-500">
                                <span aria-hidden="true" class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-950/[0.04] text-[11px] font-semibold tabular-nums text-gray-600 ring-1 ring-inset ring-gray-950/10">{{ $i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="break-all font-mono text-sm text-gray-900">{{ $job['class'] }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                        <x-queue-insights::meta-pill label="Connection" :value="$job['connection'] ?? null" size="sm"/>
                                        <x-queue-insights::meta-pill label="Queue" :value="$job['queue'] ?? null" size="sm"/>
                                        @if($i === 0)
                                            <span class="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">next</span>
                                        @endif
                                    </div>
                                </div>
                                <svg class="mt-1 size-3 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </li>
                    @endforeach
                </ol>
                @if($chainTruncated)
                    <p class="mt-2 text-[11px] text-amber-700">
                        Showing the first {{ $chainCap }} of {{ $chainTotal }} chained jobs. The remaining {{ $chainTotal - $chainCap }} are hidden to keep the modal responsive.
                    </p>
                @endif
                <p class="mt-3 text-[11px] text-gray-500">
                    Chain context comes from the serialized job body — the next link's own connection/queue overrides the parent chain defaults when set. These jobs haven't run yet, so individual run history isn't available here.
                </p>
            </div>

            {{-- Chain item drill-down. Same shape as failed-modal — Alpine
                client-side swap on `chainIndex` keeps this a zero-round-trip
                interaction once the modal is open. Capped to the same
                window as the list above. --}}
            <div class="p-4" x-show="view === 'chain-detail'" x-cloak>
                @foreach($chainJobs as $i => $job)
                    <div x-show="chainIndex === {{ $i }}" x-cloak>
                        <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500">
                            Chained job {{ $i + 1 }} of {{ $chainTotal }}
                            @if($i === 0)<span class="ml-1 rounded-md bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">next</span>@endif
                        </p>
                        <div class="rounded-xl bg-linear-to-br from-gray-50 to-white p-4 ring-1 ring-inset ring-gray-950/10">
                            <dl>
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Class</dt>
                                <dd class="mt-1 break-all font-mono text-sm font-medium text-gray-900">{{ $job['class'] }}</dd>
                            </dl>
                            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                                <x-queue-insights::meta-pill label="Connection" :value="$job['connection'] ?? null"/>
                                <x-queue-insights::meta-pill label="Queue" :value="$job['queue'] ?? null"/>
                            </div>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5">
                            <div class="bg-white p-4">
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Position</dt>
                                <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-gray-900">
                                    {{ $i + 1 }} <span class="text-xs tabular-nums text-gray-400">of {{ $chainTotal }}</span>
                                </dd>
                            </div>
                            <div class="bg-white p-4">
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Status</dt>
                                <dd class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-gray-700">
                                    <span aria-hidden="true" class="inline-block size-1.5 rounded-full bg-gray-400"></span>
                                    not yet dispatched
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
                            <div class="mt-3 rounded-lg bg-white ring-1 ring-gray-950/5">
                                <p class="border-b border-gray-950/5 px-4 py-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Job instance</p>
                                <x-queue-insights::serialized-properties :properties="$chainProps"/>
                            </div>
                        @else
                            <div class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-[11px] text-gray-500 ring-1 ring-inset ring-gray-950/5">
                                Constructor data for chained jobs isn't retained on the completed stream — only routing context (class · connection · queue). Re-run the chain and inspect the failed-job modal for full property visibility.
                            </div>
                        @endif

                        <p class="mt-4 text-[11px] text-gray-500">
                            @if($i === 0)
                                This job is the next link in the chain — Laravel re-dispatches it once the parent finishes (or after a manual retry of a failed parent).
                            @else
                                This job runs after job {{ $i }} ({{ $chain['jobs'][$i - 1]['class'] }}) finishes successfully. It's still serialized inside the parent's chain context — no individual instance has been pushed onto a queue yet.
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
