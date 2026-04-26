@props([
    /** @var array<string, mixed> Row from failed_jobs (uuid/connection/queue/payload/exception/failed_at/id). */
    'failed' => [],
    /** Whether the current user passes the retryFailedJobs gate. Drives Retry button visibility. */
    'canRetry' => false,
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
    try {
        $failedAtHuman = is_string($failedAtRaw) && $failedAtRaw !== ''
            ? \Illuminate\Support\Facades\Date::parse($failedAtRaw)->diffForHumans()
            : null;
    } catch (\Throwable) {
        $failedAtHuman = null;
    }

    $failedException = $failed['exception'] ?? null;

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
     x-data
     x-on:keydown.escape.window="$wire.closeFailed()"
     class="fixed inset-0 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closeFailed">
    <div x-trap.noscroll="true"
         class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 [--padding:--spacing(6)]"
         @click.stop>
        {{-- Header --}}
        <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 bg-white px-4 py-4">
            <div class="flex items-center gap-2">
                <span class="inline-flex size-6 items-center justify-center rounded-md bg-red-50 text-red-600 ring-1 ring-inset ring-red-600/20">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"
                              clip-rule="evenodd"/>
                    </svg>
                </span>
                <h3 id="qi-failed-modal-title" class="text-sm font-semibold text-gray-900">Failed job</h3>
            </div>
            <div class="flex items-center gap-1.5">
                {{-- Retry — gated, two-click confirm. Server-side `retryFailed`
                    re-runs the gate + rate-limit; UI button visibility is a
                    convenience guard. --}}
                @if($canRetry && ! empty($failed['uuid']))
                    <div x-data="{ confirming: false, t: null }" x-on:click.outside="confirming = false">
                        <button type="button"
                                x-bind:class="confirming
                                    ? 'bg-red-600 text-white ring-red-700 hover:bg-red-500'
                                    : 'bg-white text-emerald-700 ring-emerald-600/30 hover:bg-emerald-50'"
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
                        class="rounded-md p-1 text-gray-400 hover:bg-gray-950/5 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="p-4">
            {{-- Identity hero — displayName + connection/queue --}}
            <section data-section="base" class="mb-6">
                <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-gray-500">Failed</p>
                <div class="rounded-xl bg-linear-to-br from-red-50 to-white p-4 ring-1 ring-red-600/10">
                    <dl>
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Class</dt>
                        <dd class="mt-1 break-all font-mono text-sm font-medium text-gray-900">{{ $failedDisplayName ?? '—' }}</dd>
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                        <dl class="inline-flex items-center overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                            <dt class="bg-gray-950/[0.04] px-2 py-0.5 font-medium text-gray-500">Connection</dt>
                            <dd class="bg-white px-2 py-0.5 font-mono text-gray-800">{{ $failed['connection'] ?? '—' }}</dd>
                        </dl>
                        <dl class="inline-flex items-center overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10">
                            <dt class="bg-gray-950/[0.04] px-2 py-0.5 font-medium text-gray-500">Queue</dt>
                            <dd class="bg-white px-2 py-0.5 font-mono text-gray-800">{{ $failed['queue'] ?? '—' }}</dd>
                        </dl>
                    </div>
                </div>

                {{-- Metrics row --}}
                <dl class="mt-3 grid grid-cols-3 gap-px overflow-hidden rounded-xl bg-gray-950/5 ring-1 ring-gray-950/5">
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Attempts</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            @if($failedAttempts === null)
                                <span class="text-lg font-semibold tracking-tight text-gray-400">—</span>
                            @else
                                <span class="text-lg font-semibold tracking-tight tabular-nums text-gray-900">{{ $failedAttempts }}</span>
                                @if($failedMaxTries !== null)
                                    <span class="text-xs tabular-nums text-gray-400">of {{ $failedMaxTries }}</span>
                                @endif
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Failed at</dt>
                        <dd class="mt-1">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $failedAtHuman ?? '—' }}</p>
                            @if($failedAtRaw)
                                <p class="truncate font-mono text-[10px] text-gray-400">{{ $failedAtRaw }}</p>
                            @endif
                        </dd>
                        {{-- Wait time — `—` when no sample (legacy / driver). --}}
                        <dd class="mt-1.5 text-[11px] tabular-nums text-gray-500"
                            title="Wait time = enqueue → worker pickup">
                            <span class="text-gray-400">wait</span>
                            <span class="font-medium text-gray-700">{{ $failedWaitHumanized ?? '—' }}</span>
                            @if(is_numeric($failedWaitMs) && (int) $failedWaitMs > 0)
                                <span class="text-gray-400">({{ (int) $failedWaitMs }} ms)</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Row ID</dt>
                        <dd class="mt-1 text-lg font-semibold tracking-tight tabular-nums text-gray-900">
                            #{{ $failed['id'] ?? '—' }}</dd>
                    </div>
                </dl>

                {{-- UUID --}}
                <dl class="mt-3 flex items-center gap-2 border-t border-gray-950/5 pt-3">
                    <dt class="shrink-0 text-[10px] font-medium uppercase tracking-wider text-gray-400">UUID</dt>
                    <dd class="flex min-w-0 flex-1 items-center gap-1.5">
                        <code id="qi-failed-uuid"
                              class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600">{{ $failed['uuid'] ?? '—' }}</code>
                        <x-queue-insights::copy-button target="qi-failed-uuid" label="Copy UUID" variant="icon" class="shrink-0"/>
                    </dd>
                </dl>
            </section>

            {{-- Exception + parsed stack trace via shared component.
                The component renders the header (exception class + message in red) AND
                each frame structurally — no separate summary box needed. --}}
            @if (is_string($failedException) && $failedException !== '')
                <section data-section="trace" class="mb-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500">Exception &amp; stack trace</p>
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
            @if (is_string($failedPayloadRaw) && $failedPayloadRaw !== '')
                <section data-section="payload" class="mb-2">
                    <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Payload</p>
                    <x-queue-insights::structured-payload :payload="$failedPayloadDecoded ?? $failedPayloadRaw"/>
                </section>
            @endif

            {{-- Hidden source nodes for the Copy buttons. The clipboard handler
                in layouts/app.blade.php reads `textContent` by id; <pre> preserves
                newlines + indentation through copy/paste. --}}
            @if (is_string($failedException) && $failedException !== '')
                <pre id="qi-failed-stack" class="hidden" aria-hidden="true">{{ $failedException }}</pre>
            @endif
            <pre id="qi-failed-markdown" class="hidden" aria-hidden="true">{{ $failedMarkdown }}</pre>
        </div>
    </div>
</div>
