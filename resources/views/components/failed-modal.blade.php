@props([
    /** @var array<string, mixed> Row from failed_jobs (uuid/connection/queue/payload/exception/failed_at/id). */
    'failed' => [],
    /** Whether the current user passes the retryFailedJobs gate. Drives Retry button visibility. */
    'canRetry' => false,
    /** @var 'raw'|'json' Active payload tab — shared Livewire state with the completed-jobs modal. */
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
    $failedPayloadRaw = $failed['payload'] ?? null;
    $failedPayloadDecoded = is_string($failedPayloadRaw) ? json_decode($failedPayloadRaw, true) : null;
    $failedDisplayName = is_array($failedPayloadDecoded) && isset($failedPayloadDecoded['displayName']) && is_string($failedPayloadDecoded['displayName'])
        ? $failedPayloadDecoded['displayName']
        : null;
    $failedMaxTries = is_array($failedPayloadDecoded) && isset($failedPayloadDecoded['maxTries'])
        ? $failedPayloadDecoded['maxTries']
        : null;
    $failedAttempts = is_array($failedPayloadDecoded) && isset($failedPayloadDecoded['attempts'])
        ? (int) $failedPayloadDecoded['attempts']
        : null;

    $failedAtRaw = $failed['failed_at'] ?? null;

    $failedException = $failed['exception'] ?? null;

    // Class FQCN title — namespace faded, leaf bold. Failed jobs carry the
    // class only as `payload.displayName`, so that doubles as the title.
    $failedClassNs = '';
    $failedClassLeaf = '';
    if (is_string($failedDisplayName) && $failedDisplayName !== '') {
        $lastBackslash = strrpos($failedDisplayName, '\\');
        $failedClassNs = $lastBackslash !== false ? substr($failedDisplayName, 0, $lastBackslash + 1) : '';
        $failedClassLeaf = $lastBackslash !== false ? substr($failedDisplayName, $lastBackslash + 1) : $failedDisplayName;
    }

    // Body fed to the Structured payload tab — job-config keys + tags
    // stripped so it stays job-payload-focused; those surface in the
    // job-config hero instead. Mirrors the completed-jobs details modal.
    // The Sanitized JSON tab keeps the full body via `$failedPayloadPretty`
    // (computed below for the Markdown export — reused here).
    $heroBodyKeys = ['maxTries', 'maxExceptions', 'timeout', 'backoff', 'retryUntil', 'failOnTimeout', 'tags'];
    $failedPayloadFiltered = $failedPayloadDecoded;
    if (is_array($failedPayloadFiltered)) {
        foreach ($heroBodyKeys as $stripKey) {
            unset($failedPayloadFiltered[$stripKey]);
        }
    }

    // Forward chain — `data.command` in the failed_jobs payload carries the
    // remaining chain. Encrypted commands return null gracefully.
    $failedChain = \SanderMuller\QueueInsights\Support\RowEnricher::chainFromPayload($failedPayloadDecoded);

    // Batch id — plaintext at `data.batchId` even on ShouldBeEncrypted jobs.
    // Drives the batch chip so operators can jump from a failed job to the
    // batch it belongs to. Suppressed when batches.enabled = false to keep
    // the chip from rendering an unreachable target.
    $failedBatchId = null;
    if (\SanderMuller\QueueInsights\Support\Config::bool('batches.enabled', true)
        && is_array($failedPayloadDecoded)
        && isset($failedPayloadDecoded['data']) && is_array($failedPayloadDecoded['data'])
        && isset($failedPayloadDecoded['data']['batchId'])
        && is_string($failedPayloadDecoded['data']['batchId'])
        && $failedPayloadDecoded['data']['batchId'] !== ''
    ) {
        $failedBatchId = $failedPayloadDecoded['data']['batchId'];
    }

    // Wait time — enqueue → worker pickup. Decorated server-side onto $failed.
    $failedWaitMs = $failed['wait_ms'] ?? null;
    $failedWaitHumanized = is_numeric($failedWaitMs) && (int) $failedWaitMs > 0
        ? \Carbon\CarbonInterval::milliseconds((int) $failedWaitMs)->cascade()->forHumans(['short' => true])
        : null;

    // Pretty-printed payload for the Markdown export. Falls back to the raw
    // string when JSON decode missed (e.g. unexpected payload shape).
    $failedPayloadPretty = null;
    if (is_array($failedPayloadDecoded)) {
        $failedPayloadPretty = json_encode($failedPayloadDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (is_string($failedPayloadRaw)) {
        $failedPayloadPretty = $failedPayloadRaw;
    }

    // Markdown export — handed to an AI agent or pasted into a tracker. Uses
    // a fenced block for the trace + payload so newlines survive copy/paste.
    //
    // Fence length is computed dynamically: CommonMark requires the closing
    // fence to use ≥ as many backticks as the opening, and the opening must use
    // *more* than any run inside the body. Stack traces / payloads can legitimately
    // contain ``` (SQL, shell, prior markdown), which would otherwise close the
    // block early and corrupt the exported Markdown. See codex review.
    $fenceFor = static function (string $body): string {
        $longest = 0;
        if (preg_match_all('/`+/', $body, $matches) > 0) {
            $longest = max(array_map('strlen', $matches[0]));
        }

        return str_repeat('`', max(3, $longest + 1));
    };

    $mdLines = [
        '# Failed job',
        '',
        '- **Class:** '.($failedDisplayName ?? '—'),
        '- **Connection:** '.($failed['connection'] ?? '—'),
        '- **Queue:** '.($failed['queue'] ?? '—'),
    ];
    if ($failedAttempts !== null) {
        $mdLines[] = '- **Attempts:** '.$failedAttempts.($failedMaxTries !== null ? ' of '.$failedMaxTries : '');
    }
    if (is_string($failedAtRaw) && $failedAtRaw !== '') {
        $mdLines[] = '- **Failed at:** '.$failedAtRaw;
    }
    if (is_numeric($failedWaitMs)) {
        $mdLines[] = '- **Wait:** '.((int) $failedWaitMs).' ms';
    }
    if (! empty($failed['uuid'])) {
        $mdLines[] = '- **UUID:** `'.$failed['uuid'].'`';
    }
    if (! empty($failed['id'])) {
        $mdLines[] = '- **Row ID:** '.$failed['id'];
    }
    // Backward chain lineage — surface the dispatching parent's uuid (and
    // class when still in the 7-day retention window) so the AI handoff
    // can root-cause failures upstream of the actual failure point.
    if (! empty($failed['parent_uuid'])) {
        $parentLine = '- **Parent:** `'.$failed['parent_uuid'].'`';
        if (! empty($failed['parent_class'])) {
            $parentLine .= ' ('.$failed['parent_class'].')';
        }
        $mdLines[] = $parentLine;
    }
    // Initiator — origin (coarse entry point) + call site (dispatch
    // file:line), resolved lazily by DashboardData::resolveFailedInitiator.
    // Omitted when absent, mirroring the Parent line above.
    if (! empty($failed['origin'])) {
        $mdLines[] = '- **Origin:** '.$failed['origin'];
    }
    if (! empty($failed['call_site'])) {
        $mdLines[] = '- **Dispatched from:** `'.$failed['call_site'].'`';
    }
    if (is_string($failedException) && $failedException !== '') {
        $fence = $fenceFor($failedException);
        $mdLines[] = '';
        $mdLines[] = '## Exception';
        $mdLines[] = '';
        $mdLines[] = $fence;
        $mdLines[] = $failedException;
        $mdLines[] = $fence;
    }
    if (is_string($failedPayloadPretty) && $failedPayloadPretty !== '') {
        $fence = $fenceFor($failedPayloadPretty);
        $mdLines[] = '';
        $mdLines[] = '## Payload';
        $mdLines[] = '';
        $mdLines[] = $fence.'json';
        $mdLines[] = $failedPayloadPretty;
        $mdLines[] = $fence;
    }
    $failedMarkdown = implode("\n", $mdLines)."\n";
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-failed-modal-title"
     x-data="{ view: 'job', chainIndex: 0 }"
     x-on:keydown.escape.window="
        if (view === 'chain-detail') { view = 'chain'; }
        else if (view === 'chain') { view = 'job'; }
        else { $wire.closeFailed(); }
     "
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closeFailed">

    <div x-trap.noscroll="true"
         class="max-h-[88vh] w-full max-w-5xl overflow-auto rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
         @click.stop>
        @include('queue-insights::partials.failed-modal-header')

        <div x-show="view === 'job'">
            <div class="grid md:grid-cols-[22rem_1fr]">
                {{-- Left rail — class title + metadata description list --}}
                <div class="border-b border-gray-950/5 p-5 md:border-b-0 md:border-r dark:border-white/10">
                    {{-- Class FQCN as the modal title — namespace fades to a soft
                        secondary, base-class leaf bold so focus lands on the job
                        name. Matches the completed-jobs details modal. --}}
                    @if($failedClassLeaf !== '')
                        <p class="mb-4 break-all font-mono text-sm">@if($failedClassNs !== '')<span class="text-gray-400 dark:text-gray-500">{{ $failedClassNs }}</span>@endif<span class="font-semibold text-gray-900 dark:text-gray-100">{{ $failedClassLeaf }}</span></p>
                    @else
                        <p class="mb-4 font-mono text-sm text-gray-400 dark:text-gray-500">—</p>
                    @endif

                    <div class="mb-3 flex items-center gap-2">
                        <span class="inline-flex size-5 items-center justify-center rounded-md bg-red-50 text-red-600 ring-1 ring-inset ring-red-600/20 dark:bg-red-900/40 dark:text-red-400 dark:ring-red-400/30">
                            <x-queue-insights::icon-error-circle class="size-3"/>
                        </span>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">Failed</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="Connection" :value="$failed['connection'] ?? null"/>
                        <x-queue-insights::meta-pill label="Queue" :value="$failed['queue'] ?? null"/>
                    </div>

                    <dl class="mt-4 divide-y divide-gray-950/5 border-t border-gray-950/5 text-xs dark:divide-white/10 dark:border-white/10">
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Attempts</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">
                                @if($failedAttempts === null)—@else{{ $failedAttempts }}@if($failedMaxTries !== null) <span class="text-gray-400 dark:text-gray-500">of {{ $failedMaxTries }}</span>@endif @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Failed at</dt>
                            <dd class="min-w-0 text-right">
                                <x-queue-insights::qi-time :at="$failedAtRaw" class="block truncate font-medium text-gray-900 dark:text-gray-100"/>
                                @if($failedAtRaw)
                                    <x-queue-insights::qi-time :at="$failedAtRaw" format="absolute-mono" class="block truncate text-[10px] text-gray-400 dark:text-gray-500"/>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2" title="Wait time = enqueue → worker pickup">
                            <dt class="text-gray-500 dark:text-gray-400">Wait</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $failedWaitHumanized ?? '—' }}
                                @if(is_numeric($failedWaitMs) && (int) $failedWaitMs > 0)
                                    <span class="text-gray-400 dark:text-gray-500">({{ (int) $failedWaitMs }} ms)</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Row ID</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">#{{ $failed['id'] ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">UUID</dt>
                            <dd class="flex min-w-0 items-center gap-1.5">
                                <code id="qi-failed-uuid"
                                      class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $failed['uuid'] ?? '—' }}</code>
                                <x-queue-insights::copy-button target="qi-failed-uuid" label="Copy UUID" variant="icon" class="shrink-0"/>
                            </dd>
                        </div>
                        @php
                            // Initiator — who started this job. Origin is the coarse
                            // entry point (http/artisan/schedule); call_site is the
                            // exact dispatch file:line. Both omitted when absent.
                            $failedOrigin = is_string($failed['origin'] ?? null) && $failed['origin'] !== ''
                                ? $failed['origin']
                                : null;
                            $failedCallSite = is_string($failed['call_site'] ?? null) && $failed['call_site'] !== ''
                                ? $failed['call_site']
                                : null;
                        @endphp
                        @if($failedOrigin !== null)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">Origin</dt>
                                <dd class="min-w-0 break-all text-right font-mono text-[11px] text-gray-900 dark:text-gray-100">{{ $failedOrigin }}</dd>
                            </div>
                        @endif
                        @if($failedCallSite !== null)
                            <div class="flex items-baseline justify-between gap-3 py-2">
                                <dt class="shrink-0 text-gray-500 dark:text-gray-400">Dispatched from</dt>
                                <dd class="min-w-0 text-right">
                                    <code class="break-all rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $failed['call_site'] }}</code>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @include('queue-insights::partials.parent-lineage-row', [
                        'parentUuid' => $failed['parent_uuid'] ?? null,
                        'parentClass' => $failed['parent_class'] ?? null,
                        'parentTarget' => $failed['parent_target'] ?? null,
                        'fromClass' => $failedDisplayName,
                        'copyId' => 'qi-failed-parent-uuid',
                    ])

                    @if($failedBatchId !== null)
                        @include('queue-insights::partials.batch-teaser', ['batchId' => $failedBatchId])
                    @endif

                    @if($failedChain !== null)
                        <button type="button"
                                data-section="chain"
                                x-on:click="view = 'chain'"
                                class="mt-3 block w-full rounded-lg p-3 text-left ring-1 ring-gray-950/5 transition hover:bg-gray-50 hover:ring-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:ring-white/10 dark:hover:bg-gray-800"
                                aria-label="View full chain details">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Chain</p>
                                <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">View {{ $failedChain['remaining'] }} chained {{ $failedChain['remaining'] === 1 ? 'job' : 'jobs' }} →</span>
                            </div>
                            <p class="mt-1 break-all font-mono text-xs text-gray-900 dark:text-gray-100">{{ $failedChain['next_class'] }}</p>
                            @if($failedChain['remaining'] > 1)
                                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-300">+{{ $failedChain['remaining'] - 1 }} more chained</p>
                            @endif
                        </button>
                    @endif
                </div>

                {{-- Right column — job config + exception + payload --}}
                <div class="min-w-0 space-y-6 p-5">
                    {{-- Job-config hero — config pills + tags from the decoded
                        payload. Self-gates: renders nothing when neither is
                        present. No subtitle — the class FQCN is already the
                        left-rail title. --}}
                    @include('queue-insights::partials.job-config-hero', ['body' => $failedPayloadDecoded, 'subtitle' => null])

                    @if(is_string($failedException) && $failedException !== '')
                        <section data-section="trace">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Exception &amp; stack trace</p>
                                <x-queue-insights::copy-button target="qi-failed-stack" label="Copy stack trace" text="Copy"/>
                            </div>
                            <x-queue-insights::stack-trace :exception="$failedException"/>
                        </section>
                    @endif

                    @if(is_string($failedPayloadRaw) && $failedPayloadRaw !== '')
                        {{-- Payload — shared underline-tab partial. Structured tab
                            gets the config-stripped body; JSON tab keeps the full
                            sanitized payload as the operator's raw view. --}}
                        <section data-section="payload">
                            @include('queue-insights::partials.payload-tabs', [
                                'idPrefix' => 'qi-failed',
                                'payloadTab' => $payloadTab,
                                'structuredBody' => $failedPayloadFiltered ?? $failedPayloadRaw,
                                'jsonBody' => $failedPayloadDecoded ?? $failedPayloadRaw,
                            ])
                        </section>
                    @endif

                    @if((! is_string($failedException) || $failedException === '') && (! is_string($failedPayloadRaw) || $failedPayloadRaw === ''))
                        <div class="rounded-lg bg-gray-50 px-4 py-6 text-center text-xs text-gray-500 ring-1 ring-inset ring-gray-950/5 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10">
                            No exception trace or payload was captured for this failure.
                        </div>
                    @endif
                </div>
            </div>

            @if(is_string($failedException) && $failedException !== '')
                <pre id="qi-failed-stack" class="hidden" aria-hidden="true">{{ $failedException }}</pre>
            @endif
            <pre id="qi-failed-markdown" class="hidden" aria-hidden="true">{{ $failedMarkdown }}</pre>
        </div>

        @include('queue-insights::partials.failed-chain-views')
    </div>
</div>
