@props([
    /** @var array<string, mixed> Row from failed_jobs (uuid/connection/queue/payload/exception/failed_at/id). */
    'failed' => [],
    /** Whether the current user passes the retryFailedJobs gate. Drives Retry button visibility. */
    'canRetry' => false,
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
         class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10 [--padding:--spacing(6)]"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
            <div class="flex items-center gap-2">
                @if($expandedBatchId !== '')
                    <button type="button"
                            x-show="view === 'job'"
                            wire:click="closeFailed"
                            aria-label="Back to batch"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <x-queue-insights::icon-chevron-left class="size-3.5"/>
                        <span>Back to batch</span>
                    </button>
                @endif
                @include('queue-insights::partials.chain-back-button', ['frame' => $chainBackTop])
                <button type="button"
                        x-show="view === 'chain' || view === 'chain-detail'"
                        x-cloak
                        x-on:click="view = (view === 'chain-detail') ? 'chain' : 'job'"
                        aria-label="Back"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <x-queue-insights::icon-chevron-left class="size-3.5"/>
                    <span>Back</span>
                </button>
                <span x-show="view === 'job'" class="inline-flex size-6 items-center justify-center rounded-md bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30">
                    <x-queue-insights::icon-error-circle class="size-3.5"/>
                </span>
                <h3 id="qi-failed-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    <span x-show="view === 'job'">Failed job</span>
                    <span x-show="view === 'chain'" x-cloak>Chained jobs</span>
                    <span x-show="view === 'chain-detail'" x-cloak>Chained job details</span>
                </h3>
            </div>
            <div class="flex items-center gap-1.5">
                {{-- Retry — gated, two-click confirm. Server-side `retryFailed`
                    re-runs the gate + rate-limit; UI button visibility is a
                    convenience guard. --}}
                @if($canRetry && ! empty($failed['uuid']))
                    <div x-data="{ confirming: false, t: null }" x-on:click.outside="confirming = false">
                        <button type="button"
                                x-bind:class="confirming
                                    ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500 dark:bg-red-500 dark:ring-red-400 dark:hover:bg-red-400'
                                    : 'bg-white dark:bg-gray-900 text-emerald-700 dark:text-emerald-300 ring-emerald-600/30 dark:ring-emerald-400/30 hover:bg-emerald-50 dark:hover:bg-emerald-900/40'"
                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                                x-on:click="
                                    if (! confirming) {
                                        confirming = true;
                                        t = setTimeout(() => confirming = false, 2500);
                                        return;
                                    }
                                    clearTimeout(t);
                                    confirming = false;
                                    $wire.retryFailed(@js($failed['uuid']));
                                ">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.22Z" clip-rule="evenodd"/>
                            </svg>
                            <span x-show="! confirming">Retry</span>
                            <span x-show="confirming" x-cloak>Confirm retry?</span>
                        </button>
                    </div>
                @endif

                {{-- Markdown export — convenient hand-off to an AI agent / tracker.
                    Source text lives in the hidden <pre> below. --}}
                <x-queue-insights::copy-button
                    target="qi-failed-markdown"
                    label="Copy failure details as Markdown"
                    text="Copy markdown"/>
                <button type="button"
                        wire:click="closeFailed"
                        aria-label="Close failed job modal"
                        class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <x-queue-insights::icon-close/>
                </button>
            </div>
        </div>

        <div class="p-4" x-show="view === 'job'">
            {{-- Identity hero — displayName + connection/queue --}}
            <section data-section="base" class="mb-6">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Failed</p>
                <div class="rounded-xl bg-linear-to-br from-red-50 to-white p-4 ring-1 ring-red-600/10 dark:from-red-900/40 dark:to-gray-900 dark:ring-red-400/30">
                    <dl>
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Class</dt>
                        <dd class="mt-1 break-all font-mono text-sm font-medium text-gray-900 dark:text-gray-100">{{ $failedDisplayName ?? '—' }}</dd>
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                        <x-queue-insights::meta-pill label="Connection" :value="$failed['connection'] ?? null"/>
                        <x-queue-insights::meta-pill label="Queue" :value="$failed['queue'] ?? null"/>
                        @if($failedBatchId !== null)
                            @include('queue-insights::partials.batch-chip', ['batchId' => $failedBatchId])
                        @endif
                    </div>
                </div>

                @include('queue-insights::partials.parent-lineage-row', [
                    'parentUuid' => $failed['parent_uuid'] ?? null,
                    'parentClass' => $failed['parent_class'] ?? null,
                    'parentTarget' => $failed['parent_target'] ?? null,
                    'fromClass' => $failedDisplayName,
                    'copyId' => 'qi-failed-parent-uuid',
                ])

                @if($failedChain !== null)
                    <button type="button"
                            data-section="chain"
                            x-on:click="view = 'chain'"
                            class="mt-3 block w-full text-left rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 transition hover:bg-gray-50 dark:hover:bg-gray-800 hover:ring-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                            aria-label="View full chain details">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Chain</p>
                            <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">View {{ $failedChain['remaining'] }} chained {{ $failedChain['remaining'] === 1 ? 'job' : 'jobs' }} →</span>
                        </div>
                        <dl class="mt-2 space-y-1 text-xs">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <dt class="text-gray-400 dark:text-gray-400">Next</dt>
                                <dd class="break-all font-mono text-gray-900 dark:text-gray-100">{{ $failedChain['next_class'] }}</dd>
                                @if($failedChain['remaining'] > 1)
                                    <dd class="text-gray-500 dark:text-gray-300">(+{{ $failedChain['remaining'] - 1 }} more chained)</dd>
                                @endif
                            </div>
                            @if($failedChain['chain_queue'] !== null || $failedChain['chain_connection'] !== null)
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <dt class="text-gray-400 dark:text-gray-400">Queue</dt>
                                    <dd class="font-mono text-gray-700 dark:text-gray-300">{{ $failedChain['chain_queue'] ?? '—' }}</dd>
                                    @if($failedChain['chain_connection'] !== null)
                                        <dd class="text-gray-400 dark:text-gray-400">·</dd>
                                        <dd class="font-mono text-gray-700 dark:text-gray-300">{{ $failedChain['chain_connection'] }}</dd>
                                    @endif
                                </div>
                            @endif
                        </dl>
                    </button>
                @endif

                {{-- Metrics row --}}
                <dl class="mt-3 grid grid-cols-3 gap-px overflow-hidden rounded-xl bg-gray-950/5 dark:bg-white/10 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="bg-white dark:bg-gray-900 p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Attempts</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            @if($failedAttempts === null)
                                <span class="text-lg font-semibold tracking-tight text-gray-400 dark:text-gray-400">—</span>
                            @else
                                <span class="text-lg font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">{{ $failedAttempts }}</span>
                                @if($failedMaxTries !== null)
                                    <span class="text-xs tabular-nums text-gray-400 dark:text-gray-400">of {{ $failedMaxTries }}</span>
                                @endif
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white dark:bg-gray-900 p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Failed at</dt>
                        <dd class="mt-1">
                            <x-queue-insights::qi-time :at="$failedAtRaw" class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100"/>
                            @if($failedAtRaw)
                                <x-queue-insights::qi-time :at="$failedAtRaw" format="absolute-mono" class="block truncate text-[10px] text-gray-400 dark:text-gray-400"/>
                            @endif
                        </dd>
                        {{-- Wait time — `—` when no sample (legacy / driver). --}}
                        <dd class="mt-1.5 text-[11px] tabular-nums text-gray-500 dark:text-gray-300"
                            title="Wait time = enqueue → worker pickup">
                            <span class="text-gray-400 dark:text-gray-400">wait</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $failedWaitHumanized ?? '—' }}</span>
                            @if(is_numeric($failedWaitMs) && (int) $failedWaitMs > 0)
                                <span class="text-gray-400 dark:text-gray-400">({{ (int) $failedWaitMs }} ms)</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white dark:bg-gray-900 p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Row ID</dt>
                        <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">
                            #{{ $failed['id'] ?? '—' }}</dd>
                    </div>
                </dl>

                {{-- UUID --}}
                <dl class="mt-3 flex items-center gap-2 border-t border-gray-950/5 dark:border-white/10 pt-3">
                    <dt class="shrink-0 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">UUID</dt>
                    <dd class="flex min-w-0 flex-1 items-center gap-1.5">
                        <code id="qi-failed-uuid"
                              class="truncate rounded bg-gray-950/5 dark:bg-white/10 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:text-gray-300">{{ $failed['uuid'] ?? '—' }}</code>
                        <x-queue-insights::copy-button target="qi-failed-uuid" label="Copy UUID" variant="icon" class="shrink-0"/>
                    </dd>
                </dl>
            </section>

            {{-- Exception + parsed stack trace via shared component.
                The component renders the header (exception class + message in red) AND
                each frame structurally — no separate summary box needed. --}}
            @if(is_string($failedException) && $failedException !== '')
                <section data-section="trace" class="mb-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Exception &amp; stack trace</p>
                        <x-queue-insights::copy-button
                            target="qi-failed-stack"
                            label="Copy stack trace"
                            text="Copy"/>
                    </div>
                    <x-queue-insights::stack-trace :exception="$failedException"/>
                </section>
            @endif

            {{-- Payload (decoded from failed_jobs.payload column) — same grouped
                treatment as the completed-jobs Raw tab via the shared component. --}}
            @if(is_string($failedPayloadRaw) && $failedPayloadRaw !== '')
                <section data-section="payload" class="mb-2">
                    <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Payload</p>
                    <x-queue-insights::structured-payload :payload="$failedPayloadDecoded ?? $failedPayloadRaw"/>
                </section>
            @endif

            {{-- Hidden source nodes for the Copy buttons. The clipboard handler
                in layouts/app.blade.php reads `textContent` by id; <pre> preserves
                newlines + indentation through copy/paste. --}}
            @if(is_string($failedException) && $failedException !== '')
                <pre id="qi-failed-stack" class="hidden" aria-hidden="true">{{ $failedException }}</pre>
            @endif
            <pre id="qi-failed-markdown" class="hidden" aria-hidden="true">{{ $failedMarkdown }}</pre>
        </div>

        @if($failedChain !== null)
            @php
                // Bound the rendered chain to keep a long, job-supplied chain
                // from blowing up modal DOM. Both the list AND the per-item
                // detail blocks render N entries; without a cap a job with a
                // 1000-link chain would ship 2000 hidden DOM nodes per
                // modal-open. 50 is well above any realistic chain.
                $chainCap = 50;
                $chainJobs = array_slice($failedChain['jobs'], 0, $chainCap);
                $chainTotal = count($failedChain['jobs']);
                $chainTruncated = $chainTotal > $chainCap;
            @endphp
            <div class="p-4" x-show="view === 'chain'" x-cloak data-section="chain-detail">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Chain ({{ $failedChain['remaining'] }} {{ $failedChain['remaining'] === 1 ? 'job' : 'jobs' }} after this one)</p>
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
                                            <span class="rounded-md bg-emerald-50 dark:bg-emerald-900/40 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-600/20 dark:ring-emerald-400/30">next</span>
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
                    Chain context comes from the failed_jobs payload — the next link's own connection/queue overrides the parent chain defaults when set. These jobs haven't run yet, so individual run history isn't available here.
                </p>
            </div>

            {{-- Chain item drill-down. Driven by Alpine `chainIndex` state set
                from the chain-list buttons above; the server renders each
                possible chain entry here and Alpine swaps visibility client-
                side. Avoids extra round-trips for click-to-detail. Capped to
                the same window as the list above so the hidden DOM stays
                bounded. --}}
            <div class="p-4" x-show="view === 'chain-detail'" x-cloak>
                @foreach($chainJobs as $i => $job)
                    <div x-show="chainIndex === {{ $i }}" x-cloak>
                        <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                            Chained job {{ $i + 1 }} of {{ $chainTotal }}
                            @if($i === 0)<span class="ml-1 rounded-md bg-emerald-50 dark:bg-emerald-900/40 px-1.5 py-0.5 font-medium text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-600/20 dark:ring-emerald-400/30">next</span>@endif
                        </p>
                        <div class="rounded-xl bg-linear-to-br from-gray-50 to-white p-4 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
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
                                <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">Status</dt>
                                <dd class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <span aria-hidden="true" class="inline-block size-1.5 rounded-full bg-gray-400"></span>
                                    not yet dispatched
                                </dd>
                            </div>
                        </dl>

                        {{-- Per-chained-job properties — extracted at render time
                            from the failed_jobs payload, so the user sees the same
                            constructor-bound data the worker would deserialize on
                            retry. Empty for jobs whose chained body was unparseable
                            (encrypted) or whose constructor stored nothing. --}}
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
                                No constructor properties available for this chained job — either it carries no user-bound data, or its serialized body wasn't parseable (e.g. encrypted blob).
                            </div>
                        @endif

                        <p class="mt-4 text-[11px] text-gray-500 dark:text-gray-300">
                            @if($i === 0)
                                This job runs first once the failed parent is retried — Laravel re-dispatches the chain head with the remaining {{ $chainTotal - 1 }} {{ $chainTotal === 2 ? 'link' : 'links' }} attached.
                            @else
                                This job runs after job {{ $i }} ({{ $failedChain['jobs'][$i - 1]['class'] }}) finishes successfully. It's still serialized inside the parent's chain context — no individual instance has been pushed onto a queue yet.
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
