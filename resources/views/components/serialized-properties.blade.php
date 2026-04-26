{{-- Recursive renderer for an extracted property map (output of
    `SerializedCommandReader::extract()->properties` or `expandObject()`).
    Each property row:
      - scalar / null  → rendered inline
      - array          → JSON inline (truncates past 200 chars with expand)
      - object         → class name + Expand button revealing this same component
                         called recursively on the inner properties
    Used by `structured-payload` for the Job instance panel.

    Props:
      $properties — array<string, mixed>
      $depth      — int (recursion guard, default 0; capped at 6)
--}}
@props(['properties' => [], 'depth' => 0])

@php
    $maxDepth = 6;
    $reader = \SanderMuller\QueueInsights\Support\SerializedCommandReader::class;
@endphp

<dl class="divide-y divide-gray-950/5">
    @foreach ($properties as $propName => $propValue)
        @php
            $isObject = is_object($propValue);
            $isArray = is_array($propValue);
            $rendered = $reader::summarize($propValue);
            $truncated = ! $isObject && strlen($rendered) > 200;
            $nestedClass = $isObject ? $reader::classNameOf($propValue) : null;
        @endphp
        <div class="grid grid-cols-[max-content_1fr] gap-x-4 px-4 py-2 text-xs"
             @if ($isObject || $truncated) x-data="{ expanded: false }" @endif>
            <dt class="font-mono font-medium text-gray-600">{{ $propName }}</dt>
            <dd class="break-all font-mono {{ $propValue === null ? 'text-gray-400' : ($isObject ? 'text-purple-700' : ($isArray ? 'text-blue-700' : 'text-gray-900')) }}">
                @if ($isObject)
                    <span>{{ $nestedClass ?? 'object' }}</span>
                    @if ($depth < $maxDepth)
                        <button type="button"
                                @click="expanded = ! expanded"
                                class="ml-1 rounded bg-gray-950/5 px-1.5 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                            <span x-show="! expanded">expand</span>
                            <span x-show="expanded" x-cloak>collapse</span>
                        </button>
                        <div x-show="expanded" x-cloak class="mt-2 -mx-4 -mb-2 border-t border-gray-950/5 bg-gray-950/[0.02]">
                            @php $nested = $reader::expandObject($propValue); @endphp
                            @if (count($nested) === 0)
                                <p class="px-4 py-2 text-[11px] text-gray-500">No properties.</p>
                            @else
                                <x-queue-insights::serialized-properties :properties="$nested" :depth="$depth + 1"/>
                            @endif
                        </div>
                    @else
                        <span class="ml-1 text-[10px] text-gray-400">(max depth)</span>
                    @endif
                @elseif ($truncated)
                    <span x-show="! expanded">{{ substr($rendered, 0, 200) }}…</span>
                    <span x-show="expanded" x-cloak>{{ $rendered }}</span>
                    <button type="button" @click="expanded = ! expanded"
                            class="ml-1 rounded bg-gray-950/5 px-1.5 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
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
