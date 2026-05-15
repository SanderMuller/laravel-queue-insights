{{-- Failed-job chain + chain-detail views — shared across modal layout
    variants. Expects $failedChain in scope (decoded by the parent modal). --}}
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
