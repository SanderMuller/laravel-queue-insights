@props([
    /**
     * Hydrated run detail. `null` when the deep-linked run id no longer
     * exists (post-7d TTL); the modal shows an "Expired" empty state.
     *
     * @var ?array{
     *   task_key: string,
     *   run_id: string,
     *   started_at_ms: ?int,
     *   finished_at_ms: ?int,
     *   runtime_ms: ?int,
     *   exit_code: ?int,
     *   status: string,
     *   skip_reason: ?string,
     *   host_id: string,
     *   is_background: bool,
     *   recovered_from_hung: bool,
     *   exception: ?array<string, mixed>,
     *   app_context: ?array<array-key, mixed>,
     *   environment: ?array<array-key, mixed>,
     *   has_output: bool,
     *   correlated_jobs: list<string>,
     * }
     */
    'run' => null,
    /** Optional output blob; suppressed when `has_output` is false. */
    'output' => null,
    /** Human label for the parent task (description ?? command). */
    'taskLabel' => null,
    /** Whether the parent task is a closure — drives the output-skip hint. */
    'isClosure' => false,
])

@php
    $formatDuration = static function (?int $ms): string {
        if ($ms === null || $ms <= 0) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return number_format($ms / 1000, 2) . 's';
    };

    $statusBadge = static function (string $status): array {
        return match ($status) {
            'success' => ['label' => '✓ ok', 'cls' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/30'],
            'failed'  => ['label' => '✗ failed', 'cls' => 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300 ring-red-600/20 dark:ring-red-400/30'],
            'skipped' => ['label' => '↷ skipped', 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'],
            'hung'    => ['label' => '⏳ hung', 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/30'],
            'missed'  => ['label' => '⏰ missed', 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/30'],
            'starting' => ['label' => '… running', 'cls' => 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/30'],
            default   => ['label' => $status, 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 ring-gray-950/10 dark:ring-white/10'],
        };
    };

    $accentStripe = static function (string $status): string {
        return match ($status) {
            'failed', 'hung' => 'bg-red-500 dark:bg-red-400',
            'skipped', 'missed' => 'bg-amber-500 dark:bg-amber-400',
            'success' => 'bg-emerald-500 dark:bg-emerald-400',
            default => 'bg-sky-500 dark:bg-sky-400',
        };
    };

    $skipReasonExplanation = static function (string $reason): string {
        return match ($reason) {
            'mutex' => 'A previous run of this task was still in progress (`withoutOverlapping`).',
            'one_server' => 'Another host already claimed this fire (`onOneServer`).',
            'maintenance' => 'The application was in maintenance mode and the task is not flagged `evenInMaintenanceMode`.',
            'between' => 'The fire fell outside the `between(...)` window declared on the task.',
            'filter' => 'A `when()` / `skip()` / `unlessBetween(...)` filter on the task evaluated to skip.',
            default => 'Reason could not be deduced — the listener fell back to "unknown".',
        };
    };

    // Markdown export — handed to AI agent or pasted into a tracker.
    $mdLines = ['# Scheduled run'];
    if ($run !== null) {
        $mdLines[] = '';
        $mdLines[] = '- **Task:** ' . ($taskLabel ?? $run['task_key']);
        $mdLines[] = '- **Run ID:** `' . $run['run_id'] . '`';
        $mdLines[] = '- **Status:** ' . $run['status'];
        $mdLines[] = '- **Host:** ' . $run['host_id'];
        if ($run['exit_code'] !== null) {
            $mdLines[] = '- **Exit code:** ' . $run['exit_code'];
        }
        if ($run['runtime_ms'] !== null) {
            $mdLines[] = '- **Runtime:** ' . $formatDuration($run['runtime_ms']);
        }
        if ($run['skip_reason'] !== null) {
            $mdLines[] = '- **Skip reason:** ' . $run['skip_reason'];
        }
        if ($run['recovered_from_hung']) {
            $mdLines[] = '- **Recovered from hung:** yes';
        }
        if (is_array($run['exception']) && isset($run['exception']['class'])) {
            $exClass = is_string($run['exception']['class']) ? $run['exception']['class'] : 'Throwable';
            $exMsg = is_string($run['exception']['message'] ?? null) ? $run['exception']['message'] : '';
            $mdLines[] = '';
            $mdLines[] = '## Exception';
            $mdLines[] = '';
            $mdLines[] = '```';
            $mdLines[] = $exClass . ': ' . $exMsg;
            // Inner (root-cause) exception — captured discretely because the
            // 4000-char trace tail can truncate it out.
            if (is_string($run['exception']['inner_class'] ?? null)) {
                $innerMsg = is_string($run['exception']['inner_message'] ?? null) ? $run['exception']['inner_message'] : '';
                $mdLines[] = 'Caused by: ' . $run['exception']['inner_class'] . ': ' . $innerMsg;
            }
            if (is_string($run['exception']['file'] ?? null)) {
                $mdLines[] = 'at ' . $run['exception']['file'] . (isset($run['exception']['line']) ? ':' . $run['exception']['line'] : '');
            }
            if (is_string($run['exception']['trace_tail'] ?? null) && $run['exception']['trace_tail'] !== '') {
                $mdLines[] = '';
                $mdLines[] = $run['exception']['trace_tail'];
            }
            $mdLines[] = '```';
        }
        $runEnvironment = is_array($run['environment'] ?? null) ? $run['environment'] : [];
        $runAppContext = is_array($run['app_context'] ?? null) ? $run['app_context'] : [];
        if ($runEnvironment !== []) {
            $mdLines[] = '';
            $mdLines[] = '## Environment';
            $mdLines[] = '';
            foreach (['host', 'pid', 'env', 'release'] as $envKey) {
                $envVal = $runEnvironment[$envKey] ?? null;
                if ($envVal !== null && $envVal !== '') {
                    $mdLines[] = '- **' . ucfirst($envKey) . ':** ' . str_replace(["\r", "\n"], ' ', (string) $envVal);
                }
            }
        }
        if ($runAppContext !== []) {
            $mdLines[] = '';
            $mdLines[] = '## Context';
            $mdLines[] = '';
            foreach ($runAppContext as $ctxKey => $ctxVal) {
                $rendered = is_scalar($ctxVal) ? (string) $ctxVal : json_encode($ctxVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                // Collapse newlines — a context value must not break the export
                // list or inject markdown headings/fences into the AI paste.
                $mdLines[] = '- **' . $ctxKey . ':** ' . str_replace(["\r", "\n"], ' ', (string) $rendered);
            }
        }
        if ($run['correlated_jobs'] !== []) {
            $mdLines[] = '';
            $mdLines[] = '## Jobs dispatched during this run';
            $mdLines[] = '';
            foreach ($run['correlated_jobs'] as $uuid) {
                $mdLines[] = '- `' . $uuid . '`';
            }
        }
    }
    $runMarkdown = implode("\n", $mdLines) . "\n";
@endphp

<div role="dialog"
     aria-modal="true"
     aria-labelledby="qi-schedule-run-modal-title"
     x-data
     x-on:keydown.escape.window="$wire.closeRunModal()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
     wire:click="closeRunModal">
    <div x-trap.noscroll="true"
         class="max-h-[88vh] w-full max-w-5xl overflow-auto rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
         @click.stop>
        @if($run === null)
            {{-- Expired empty state --}}
            <div class="flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 px-4 py-4">
                <h3 id="qi-schedule-run-modal-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">Run details</h3>
                <button type="button"
                        wire:click="closeRunModal"
                        aria-label="Close run modal"
                        class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    <x-queue-insights::icon-close/>
                </button>
            </div>
            <div class="p-6 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-300">This run is no longer available.</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-400">
                    Per-run records age out after the configured retention window (default 7 days).
                </p>
            </div>
        @else
            @php $badge = $statusBadge($run['status']); @endphp
            {{-- Accent stripe --}}
            <div class="h-1 {{ $accentStripe($run['status']) }}"></div>

            {{-- Header --}}
            <div class="sticky top-0 flex items-center justify-between gap-3 border-b border-gray-950/5 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-4">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-flex items-center rounded-md py-1 pr-2 pl-1.5 text-xs font-medium ring-1 ring-inset {{ $badge['cls'] }}">{{ $badge['label'] }}</span>
                    <h3 id="qi-schedule-run-modal-title" class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ $taskLabel ?? $run['task_key'] }}
                    </h3>
                </div>
                <div class="flex items-center gap-1.5">
                    <x-queue-insights::copy-button
                        target="qi-schedule-run-markdown"
                        label="Copy run details as Markdown"
                        text="Copy markdown"/>
                    <button type="button"
                            wire:click="closeRunModal"
                            aria-label="Close run modal"
                            class="rounded-md p-1 text-gray-400 dark:text-gray-400 hover:bg-gray-950/5 dark:hover:bg-white/10 hover:text-gray-600 dark:hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <x-queue-insights::icon-close/>
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-[22rem_1fr]">
                {{-- Left rail — run metadata description list + skip reason
                    (short, fits the rail). Mirrors the failed/details modal
                    rail for cross-modal visual consistency. --}}
                <div data-section="schedule-run-meta" class="border-b border-gray-950/5 p-5 md:border-b-0 md:border-r dark:border-white/10">
                    <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Run</p>
                    <dl class="divide-y divide-gray-950/5 border-t border-gray-950/5 text-xs dark:divide-white/10 dark:border-white/10">
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Host</dt>
                            <dd class="min-w-0 break-all text-right font-mono font-medium text-gray-900 dark:text-gray-100">{{ $run['host_id'] }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Started</dt>
                            <dd class="min-w-0 text-right font-medium text-gray-900 dark:text-gray-100">
                                <x-queue-insights::qi-time :at="$run['started_at_ms']"/>
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Runtime</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $formatDuration($run['runtime_ms']) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Exit</dt>
                            <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $run['exit_code'] ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">Run ID</dt>
                            <dd class="flex min-w-0 items-center gap-1.5">
                                <code id="qi-schedule-run-id"
                                      class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $run['run_id'] }}</code>
                                <x-queue-insights::copy-button target="qi-schedule-run-id" label="Copy run id" variant="icon" class="shrink-0"/>
                            </dd>
                        </div>
                    </dl>

                    @if($run['recovered_from_hung'])
                        <p class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-amber-50 dark:bg-amber-900/40 px-2 py-1 text-[11px] text-amber-700 dark:text-amber-300 ring-1 ring-inset ring-amber-600/20 dark:ring-amber-400/30">
                            ⏳→{{ $badge['label'] }}
                            <span>recovered from hung</span>
                        </p>
                    @endif

                    @if($run['status'] === 'skipped' && $run['skip_reason'] !== null)
                        <section data-section="schedule-run-skip" class="mt-4">
                            <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Skip reason</p>
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-xs text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $run['skip_reason'] }}
                                    <span class="ml-1 text-[10px] font-normal text-gray-400 dark:text-gray-400">(best guess — deduced at skip time)</span>
                                </p>
                                <p class="mt-1 leading-snug">{{ $skipReasonExplanation($run['skip_reason']) }}</p>
                            </div>
                        </section>
                    @endif
                </div>

                {{-- Right column — exception (focal), output, correlated
                    jobs. The thing operators opened a run modal to read. --}}
                <div class="min-w-0 space-y-6 p-5">
                    @if(is_array($run['exception']) && isset($run['exception']['class']))
                        <section data-section="schedule-run-exception">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Exception</p>
                            </div>
                            <div class="rounded-xl bg-red-50 dark:bg-red-900/40 p-4 ring-1 ring-inset ring-red-600/20 dark:ring-red-400/30">
                                <p class="break-all font-mono text-sm font-medium text-red-900 dark:text-red-200">{{ $run['exception']['class'] }}</p>
                                @if(is_string($run['exception']['message'] ?? null))
                                    <p class="mt-1 text-sm text-red-800 dark:text-red-200">{{ $run['exception']['message'] }}</p>
                                @endif
                                @if(is_string($run['exception']['inner_class'] ?? null))
                                    <p class="mt-2 break-all font-mono text-[11px] text-red-700 dark:text-red-300">
                                        Caused by: <span class="font-medium">{{ $run['exception']['inner_class'] }}</span>@if(is_string($run['exception']['inner_message'] ?? null) && $run['exception']['inner_message'] !== ''): {{ $run['exception']['inner_message'] }}@endif
                                    </p>
                                @endif
                                @if(is_string($run['exception']['file'] ?? null))
                                    <p class="mt-2 break-all font-mono text-[11px] text-red-700 dark:text-red-300">
                                        at {{ $run['exception']['file'] }}@if(isset($run['exception']['line'])):{{ $run['exception']['line'] }}@endif
                                    </p>
                                @endif
                                @if(is_string($run['exception']['trace_tail'] ?? null) && $run['exception']['trace_tail'] !== '')
                                    <pre class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-md bg-white/40 dark:bg-black/30 p-3 font-mono text-[11px] leading-5 text-red-900 dark:text-red-200">{{ $run['exception']['trace_tail'] }}</pre>
                                @endif
                            </div>
                        </section>
                    @endif

                    @include('queue-insights::partials.failure-context-section', [
                        'appContext' => is_array($run['app_context'] ?? null) ? $run['app_context'] : [],
                        'environment' => is_array($run['environment'] ?? null) ? $run['environment'] : [],
                    ])

                    @if($run['has_output'])
                        @if($isClosure)
                            <section data-section="schedule-run-output-closure">
                                <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Output</p>
                                <p class="rounded-lg bg-gray-50 dark:bg-gray-800 px-3 py-2 text-[11px] text-gray-500 dark:text-gray-300 ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
                                    Closure tasks can't capture output — use <code class="rounded bg-white dark:bg-gray-900 px-1 font-mono">Log::info(...)</code> inside the closure.
                                </p>
                            </section>
                        @elseif(is_string($output) && $output !== '')
                            <section data-section="schedule-run-output">
                                <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Output</p>
                                <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 dark:bg-gray-800 p-3 font-mono text-[11px] leading-5 text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">{{ $output }}</pre>
                            </section>
                        @endif
                    @endif

                    {{-- Correlated jobs --}}
                    @include('queue-insights::partials.schedule-correlated-jobs', ['uuids' => $run['correlated_jobs']])

                    @if(! is_array($run['exception']) && ! $run['has_output'] && $run['correlated_jobs'] === [] && ! ($run['status'] === 'skipped' && $run['skip_reason'] !== null))
                        <div class="rounded-lg bg-gray-50 px-4 py-6 text-center text-xs text-gray-500 ring-1 ring-inset ring-gray-950/5 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10">
                            This run finished cleanly — no exception, no captured output, no correlated jobs.
                        </div>
                    @endif
                </div>
            </div>

            <pre id="qi-schedule-run-markdown" class="hidden" aria-hidden="true">{{ $runMarkdown }}</pre>
        @endif
    </div>
</div>
