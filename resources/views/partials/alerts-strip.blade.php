@php
    /**
     * Active alerts strip — renders one row per `Issue` returned by
     * IssueDetector::detectAll() (5s-cached read via ActiveIssuesProvider).
     * Hidden entirely when the list is empty so the dashboard stays quiet
     * during normal operation.
     *
     * Required scope vars:
     *   $activeIssues  list<\SanderMuller\QueueInsights\Alerts\Issue>
     */
@endphp
@if(! empty($activeIssues))
    <ul role="list" aria-label="Active alerts" class="flex flex-col gap-2">
        @foreach($activeIssues as $issue)
            @php
                $isCritical = $issue->severity === \SanderMuller\QueueInsights\Enums\AlertSeverity::Critical;
                $target = $issue->jobClass !== null
                    ? $issue->jobClass
                    : "{$issue->connection}:{$issue->queue}";
                $age = max(0, time() - $issue->detectedAt);
                $sinceLabel = $age < 60
                    ? "{$age}s ago"
                    : ($age < 3600 ? floor($age / 60) . 'm ago' : floor($age / 3600) . 'h ago');
            @endphp
            <li x-data="{ open: false }"
                @class([
                    'flex flex-col gap-2 rounded-lg p-3 text-sm ring-1 ring-inset',
                    'bg-red-50 text-red-900 ring-red-600/20 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-400/30' => $isCritical,
                    'bg-amber-50 text-amber-900 ring-amber-600/20 dark:bg-amber-900/40 dark:text-amber-200 dark:ring-amber-400/30' => ! $isCritical,
                ])>
                <div class="flex items-start gap-3">
                    <span @class([
                        'mt-1 inline-block size-2.5 shrink-0 rounded-full',
                        'bg-red-600 dark:bg-red-400' => $isCritical,
                        'bg-amber-500 dark:bg-amber-400' => ! $isCritical,
                    ]) aria-hidden="true"></span>

                    <div class="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                        <span class="font-semibold">{{ $issue->title }}</span>

                        <span @class([
                            'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset',
                            'bg-red-100 text-red-800 ring-red-600/20 dark:bg-red-900/60 dark:text-red-200 dark:ring-red-400/30' => $isCritical,
                            'bg-amber-100 text-amber-800 ring-amber-600/20 dark:bg-amber-900/60 dark:text-amber-200 dark:ring-amber-400/30' => ! $isCritical,
                        ])>{{ $issue->jobClass !== null ? 'class' : 'queue' }}: {{ $target }}</span>

                        <span class="text-xs opacity-75">{{ $sinceLabel }}</span>
                    </div>

                    <button type="button"
                            x-on:click="open = !open"
                            x-bind:aria-expanded="open ? 'true' : 'false'"
                            class="shrink-0 rounded p-0.5 hover:bg-gray-950/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:hover:bg-white/10"
                            aria-label="Toggle alert details">
                        <svg class="size-4 transition" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </div>

                <div x-show="open" x-cloak class="ml-5 flex flex-col gap-1 text-xs">
                    <p class="opacity-90">{{ $issue->description }}</p>

                    @if(! empty($issue->context))
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-0.5 sm:grid-cols-2">
                            @foreach($issue->context as $key => $value)
                                @if(is_scalar($value))
                                    {{-- Floats (failure_rate's `ratio` /
                                         `ratio_threshold`, backlog_growing's
                                         slope) print with PHP default
                                         precision otherwise. Round at every
                                         render site — context stays raw so
                                         host event listeners keep precision. --}}
                                    <div class="flex items-baseline gap-1.5">
                                        <dt class="font-medium opacity-75">{{ $key }}:</dt>
                                        <dd class="tabular-nums">{{ is_float($value) ? number_format($value, 2) : $value }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
