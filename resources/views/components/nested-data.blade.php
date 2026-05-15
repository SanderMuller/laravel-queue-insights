{{-- Recursive renderer for arbitrary tree-shaped data (assoc arrays / lists /
    scalars / null). Sentry-style "extra context" view: each key is a row, sub-
    objects expand on click, scalars render inline.

    Designed for `Other fields` payload values whose JSON shape is
    application-specific (e.g. `illuminate:log:context`, custom tags). The
    `serialized-properties` component handles PHP-serialized objects via
    `__PHP_Incomplete_Class`; this one handles plain decoded JSON.

    Props:
      $data  — mixed (array | scalar | null)
      $depth — int (recursion guard, default 0; capped at 6)
--}}
@props(['data' => null, 'depth' => 0])

@php
    $maxDepth = 6;
    $renderInline = static function (mixed $v): string {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_string($v) || is_int($v) || is_float($v)) {
            return (string) $v;
        }

        $encoded = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : gettype($v);
    };
@endphp

@if(is_array($data))
    @if($data === [])
        <span class="font-mono text-gray-400 dark:text-gray-400">[]</span>
    @elseif($depth >= $maxDepth)
        <span class="font-mono text-gray-400 dark:text-gray-400">{...} (max depth)</span>
    @else
        <dl class="divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach($data as $k => $v)
                @php
                    // Strings that are themselves a serialized PHP blob or a
                    // JSON container (e.g. Laravel's `illuminate:log:context`
                    // entry on a queued job) surface to the operator as a
                    // wall of escaped characters. `ValueParser` cracks them
                    // open so the renderer can treat them like any other
                    // tree node — Sentry-style transparent parsing.
                    $parsedFromString = is_string($v) && $v !== ''
                        ? \SanderMuller\QueueInsights\Support\ValueParser::parse($v)
                        : null;
                    // Scalar fallback — Laravel's `Context::dehydrate()`
                    // boxes each Context value as a PHP-serialized scalar
                    // (`s:N:"…";`, `i:N;`). parse() ignores those (returns
                    // null because they're not containers), so without this
                    // fallback the dashboard renders `s:26:"…"` literals.
                    // Unwrap the scalar so the row shows the actual value.
                    $scalarDecoded = $parsedFromString === null && is_string($v) && $v !== ''
                        ? \SanderMuller\QueueInsights\Support\ValueParser::decodeScalar($v)
                        : null;
                    $effectiveValue = $scalarDecoded !== null ? $scalarDecoded['value'] : $v;
                    $isContainer = (is_array($v) && $v !== []) || $parsedFromString !== null;
                    $containerData = $parsedFromString ?? (is_array($v) ? $v : []);
                    $rendered = $isContainer ? '' : $renderInline($effectiveValue);
                    $truncated = ! $isContainer && strlen($rendered) > 200;
                    $childCount = $isContainer ? count($containerData) : 0;
                    $childIsAssoc = $isContainer && ! array_is_list($containerData);
                    $containerKind = $parsedFromString !== null ? 'parsed' : ($childIsAssoc ? 'object' : 'array');
                    $containerSummary = match ($containerKind) {
                        'parsed' => "parsed · {$childCount} " . ($childCount === 1 ? 'entry' : 'entries'),
                        'object' => "object · {$childCount} keys",
                        default => "array · {$childCount} items",
                    };
                @endphp
                <div class="grid grid-cols-[max-content_1fr] gap-x-4 px-4 py-2 text-xs"
                     @if($isContainer || $truncated) x-data="{ expanded: false }" @endif>
                    <dt class="font-mono font-medium text-gray-600 dark:text-gray-300">{{ $k }}</dt>
                    <dd class="min-w-0 break-all font-mono {{ $v === null ? 'text-gray-400 dark:text-gray-400' : ($isContainer ? 'text-purple-700 dark:text-purple-300' : 'text-gray-900 dark:text-gray-100') }}">
                        @if($isContainer)
                            <button type="button"
                                    @click="expanded = ! expanded"
                                    class="inline-flex items-center gap-1.5 rounded bg-gray-950/5 px-1.5 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20">
                                <x-queue-insights::icon-chevron-right class="size-2.5 transition" x-bind:class="expanded ? 'rotate-90' : ''"/>
                                <span>{{ $containerSummary }}</span>
                            </button>
                            {{-- `<template x-if>` (not `x-show`) so collapsed
                                subtrees never materialize into the DOM —
                                browser skips layout/style cost on the hidden
                                branches. Blade still emits the inner HTML
                                string, but a 6-deep × N-key payload pays
                                only the string-concat cost up front, not
                                the full layout pass (codex perf review). --}}
                            <template x-if="expanded">
                                <div class="mt-1 -mx-4 -mb-2 border-t border-gray-950/5 bg-gray-950/[0.02] dark:border-white/10 dark:bg-white/5">
                                    <x-queue-insights::nested-data :data="$containerData" :depth="$depth + 1"/>
                                </div>
                            </template>
                        @elseif($truncated)
                            <span x-show="! expanded">{{ substr($rendered, 0, 200) }}…</span>
                            <span x-show="expanded" x-cloak>{{ $rendered }}</span>
                            <button type="button" @click="expanded = ! expanded"
                                    class="ml-1 rounded bg-gray-950/5 px-1.5 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-950/10 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20">
                                <span x-show="! expanded">expand</span>
                                <span x-show="expanded" x-cloak>collapse</span>
                            </button>
                        @else
                            {{ $rendered }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
@else
    <span class="font-mono {{ $data === null ? 'text-gray-400 dark:text-gray-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $renderInline($data) }}</span>
@endif
