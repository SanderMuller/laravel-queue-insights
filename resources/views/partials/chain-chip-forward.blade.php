@php
    /** @var array{next_class: string, remaining: int, chain_connection: ?string, chain_queue: ?string} $chain */
    $nextLastSlash = strrpos($chain['next_class'], '\\');
    $chainNextLast = $nextLastSlash !== false ? substr($chain['next_class'], $nextLastSlash + 1) : $chain['next_class'];
    $chainExtra = max(0, $chain['remaining'] - 1);
@endphp
<x-queue-insights::hint
    triggerClass="inline-flex items-center gap-1 rounded-md bg-gray-950/[0.04] dark:bg-white/10 px-1.5 py-0.5 font-mono text-[10px] text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 cursor-help">
    <span aria-hidden="true">↳</span>
    <span>{{ $chainNextLast }}</span>
    @if($chainExtra > 0)
        <span class="text-gray-400 dark:text-gray-400">(+{{ $chainExtra }})</span>
    @endif
    <x-slot:tip>
        <span class="block text-gray-300">Next in chain</span>
        <span class="block font-mono break-all text-white">{{ $chain['next_class'] }}</span>
        @if(! empty($chain['chain_queue']))
            <span class="mt-1 block text-gray-400">on <span class="font-mono">{{ $chain['chain_connection'] ?? '—' }}/{{ $chain['chain_queue'] }}</span></span>
        @endif
        <span class="mt-1 block text-gray-400">{{ $chain['remaining'] }} link{{ $chain['remaining'] === 1 ? '' : 's' }} remaining</span>
    </x-slot:tip>
</x-queue-insights::hint>
