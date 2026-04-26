@props([
    /** @var array<string, mixed> Stream entry decoded by QueueInsights::recentCompleted. */
    'payload' => [],
    /** @var 'raw'|'json' Active payload tab. */
    'payloadTab' => 'raw',
    /** @var 'off'|'metadata'|'full' Capture mode (queue-insights.capture.payloads). */
    'captureMode' => 'off',
])

@php
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

    // processed_at — render ISO + diffForHumans via Date:: to respect host app's factory.
    $processedAtRaw = $payload['processed_at'] ?? null;
    try {
        $processedAtHuman = is_string($processedAtRaw) && $processedAtRaw !== ''
            ? \Illuminate\Support\Facades\Date::parse($processedAtRaw)->diffForHumans()
            : null;
    } catch (\Throwable) {
        $processedAtHuman = null;
    }

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

    $hasJobConfigCards = ! empty(array_intersect(['payload_displayName', 'payload_maxTries', 'payload_timeout', 'payload_backoff'], array_keys($payload)));
    $hasStatusNote = ($payload['payload_note'] ?? null) === 'payload_not_persisted';
    $hasStatusEncodingError = ($payload['payload_error'] ?? null) === 'payload_encoding_failed';
    $hasStatusSizeOverflow = ($payload['payload_error'] ?? null) === 'payload_too_large';
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-modal-title"
     x-data
     x-on:keydown.escape.window="$wire.closePayload()"
     class="fixed inset-0 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closePayload">
    <div x-trap.noscroll="true"
         class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 [--padding:--spacing(6)]"
         @click.stop>
        <div class="sticky top-0 px-4 flex items-center justify-between gap-3 border-b border-gray-950/5 bg-white px-4 py-4">
            <h3 id="qi-modal-title" class="text-sm font-semibold text-gray-900">Details</h3>

            <div class="flex items-center gap-3">
                <span class="whitespace-nowrap rounded-md bg-gray-950/5 px-2 py-0.5 font-mono text-xs text-gray-700">capture: {{ $captureMode }}</span>
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

        <div class="p-4">

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
                        <dl class="inline-flex items-center overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                            <dt class="bg-gray-950/[0.04] px-2 py-0.5 font-medium text-gray-500">Connection</dt>
                            <dd class="bg-white px-2 py-0.5 font-mono text-gray-800">{{ $payload['connection'] ?? '—' }}</dd>
                        </dl>
                        <dl class="inline-flex items-center overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                            <dt class="bg-gray-950/[0.04] px-2 py-0.5 font-medium text-gray-500">Queue</dt>
                            <dd class="bg-white px-2 py-0.5 font-mono text-gray-800">{{ $payload['queue'] ?? '—' }}</dd>
                        </dl>
                    </div>
                </div>

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
                            <p class="truncate text-sm font-medium text-gray-900">{{ $processedAtHuman ?? '—' }}</p>
                            @if($processedAtRaw)
                                <p class="truncate font-mono text-[10px] text-gray-400">{{ $processedAtRaw }}</p>
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
                    @elseif ($hasStatusSizeOverflow)
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
                    @elseif ($hasJobConfigCards)
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
            @if($captureMode === 'off')
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
            @elseif ($captureMode === 'metadata')
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
    </div>
</div>
