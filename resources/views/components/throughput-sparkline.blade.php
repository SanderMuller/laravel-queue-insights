@props([
    /** @var list<array{timestamp: int, processed: int, failed: int}> Hourly buckets, oldest → newest. */
    'throughput' => [],
])

@php
    // Empty-array guard — the live caller (hourlyThroughput) always returns 24
    // buckets, but a future caller could pass `[]`. `max([])` raises ValueError
    // and `$viewW / 0` would divide by zero, so bail out early.
    $throughputBars = count($throughput);
@endphp

@if($throughputBars === 0)
    <section aria-label="Throughput, last 24 hours"
             class="rounded-xl bg-white p-5 ring-1 ring-gray-950/5 text-sm text-gray-500 dark:bg-gray-900 dark:ring-white/10 dark:text-gray-300">
        No throughput recorded in the window.
    </section>
@else
@php
    // SVG bar geometry — fixed viewBox lets the parent scale via CSS while we
    // compute pixel-perfect bar widths against a constant.
    $throughputMax = max(1, max(array_map(fn ($b): int => max($b['processed'], $b['failed']), $throughput)));
    $throughputViewW = 600;
    $throughputViewH = 64;
    $throughputBarW = $throughputViewW / $throughputBars;
    $throughputGap = 2;

    $buildBars = function (string $metric) use ($throughput, $throughputMax, $throughputBarW, $throughputGap, $throughputViewH): string {
        $out = '';
        foreach ($throughput as $i => $bucket) {
            $v = $bucket[$metric];
            if ($v <= 0) {
                continue;
            }
            $h = max(1, (int) round($v / $throughputMax * $throughputViewH));
            $x = $i * $throughputBarW + $throughputGap / 2;
            $w = max(1, $throughputBarW - $throughputGap);
            $y = $throughputViewH - $h;
            // pointer-events="none" so hover passes through to the full-column
            // hover-target overlay rendered later in the SVG.
            $out .= sprintf('<rect x="%.2f" y="%d" width="%.2f" height="%d" rx="1" pointer-events="none" />', $x, $y, $w, $h);
        }

        return $out;
    };

    $buildHoverTargets = function () use ($throughput, $throughputBarW, $throughputViewH): string {
        $out = '';
        foreach ($throughput as $i => $bucket) {
            $x = $i * $throughputBarW;
            $out .= sprintf(
                '<rect data-qi-bar="%d" x="%.2f" y="0" width="%.2f" height="%d" fill="transparent" pointer-events="all" class="cursor-pointer transition hover:fill-gray-950/5" x-on:mouseenter="hovered=%d" x-on:mouseleave="hovered=null" />',
                $i,
                $x,
                $throughputBarW,
                $throughputViewH,
                $i,
            );
        }

        return $out;
    };

    // Tooltip data — pre-formatted server-side so Alpine just looks up by index.
    $throughputTooltips = array_map(static function (array $b): array {
        $hour = \Illuminate\Support\Facades\Date::createFromTimestamp($b['timestamp']);

        return [
            'label' => $hour->format('M j · H:i'),
            'processed' => number_format($b['processed']),
            'failed' => number_format($b['failed']),
            'failedNonZero' => $b['failed'] > 0,
        ];
    }, $throughput);

    $throughputTotalProcessed = array_sum(array_column($throughput, 'processed'));
    $throughputTotalFailed = array_sum(array_column($throughput, 'failed'));
    $throughputNewestHour = end($throughput);
@endphp

<section aria-label="Throughput, last 24 hours"
         class="rounded-xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-300">Throughput &middot; last 24h</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 tabular-nums dark:text-gray-100">{{ number_format($throughputTotalProcessed) }}
                <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-300">processed</span></p>
        </div>
        <dl class="flex gap-5 text-sm">
            <div class="flex items-center gap-2">
                <span class="size-2 rounded-sm bg-emerald-500" aria-hidden="true"></span>
                <dt class="text-gray-500 dark:text-gray-300">Processed</dt>
                <dd class="font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($throughputTotalProcessed) }}</dd>
            </div>
            <div class="flex items-center gap-2">
                <span class="size-2 rounded-sm bg-red-500" aria-hidden="true"></span>
                <dt class="text-gray-500 dark:text-gray-300">Failed</dt>
                <dd class="font-medium tabular-nums {{ $throughputTotalFailed > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($throughputTotalFailed) }}</dd>
            </div>
            <div class="flex items-center gap-2 text-gray-500 dark:text-gray-300">
                <dt>Current hour</dt>
                <dd class="font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($throughputNewestHour['processed'] ?? 0) }}</dd>
            </div>
        </dl>
    </div>

    {{-- Sparkline + Alpine-driven hover tooltip. Tooltip renders immediately
        (no browser-default <title> 500ms delay), follows the hovered column. --}}
    <div class="relative" x-data="{ hovered: null, buckets: @js($throughputTooltips) }">
        <svg viewBox="0 0 {{ $throughputViewW }} {{ $throughputViewH }}" preserveAspectRatio="none"
             class="block h-16 w-full">
            <g class="text-emerald-500" fill="currentColor">
                {!! $buildBars('processed') !!}
            </g>
            <g class="text-red-500" fill="currentColor">
                {!! $buildBars('failed') !!}
            </g>
            {{-- Hover overlay — full-column transparent rects per hour. Each
                sets `hovered = i` on mouseenter so the HTML tooltip below can
                look up its data. Rendered last so it sits on top of the bars;
                visible bars use pointer-events="none" so hover always lands here. --}}
            <g>
                {!! $buildHoverTargets() !!}
            </g>
        </svg>
        <div x-show="hovered !== null"
             x-cloak
             x-bind:style="`left: ${((hovered + 0.5) / {{ $throughputBars }}) * 100}%`"
             class="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-[11px] text-white shadow-lg ring-1 ring-white/10">
            <p class="font-medium tabular-nums" x-text="buckets[hovered]?.label"></p>
            <p class="mt-0.5 flex items-center gap-2">
                <span class="inline-flex items-center gap-1">
                    <span class="size-1.5 rounded-sm bg-emerald-400" aria-hidden="true"></span>
                    <span class="tabular-nums" x-text="`${buckets[hovered]?.processed} processed`"></span>
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="size-1.5 rounded-sm bg-red-400" aria-hidden="true"></span>
                    <span class="tabular-nums"
                          x-bind:class="buckets[hovered]?.failedNonZero ? 'text-red-300 font-medium' : 'text-gray-400'"
                          x-text="`${buckets[hovered]?.failed} failed`"></span>
                </span>
            </p>
            <span class="absolute left-1/2 -bottom-1 size-2 -translate-x-1/2 rotate-45 bg-gray-900"
                  aria-hidden="true"></span>
        </div>
    </div>

    <div class="mt-2 flex justify-between text-[10px] font-medium uppercase tracking-wider text-gray-400 tabular-nums dark:text-gray-400">
        <span>24h ago</span>
        <span>now</span>
    </div>
</section>
@endif
