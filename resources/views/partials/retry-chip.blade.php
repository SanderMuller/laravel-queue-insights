@php
    /** @var int $attempts */
    /** @var string $context  'pickup' (in-flight / pending) | 'completed' */
    $context = $context ?? 'pickup';
    $priorAttempts = max(0, $attempts - 1);
    $priorWord = $priorAttempts === 1 ? 'time' : 'times';
@endphp
<x-queue-insights::hint
    triggerClass="shrink-0 inline-flex items-center gap-1 rounded bg-orange-50 dark:bg-orange-900/40 px-1.5 py-0.5 font-sans text-[10px] font-medium text-orange-700 dark:text-orange-300 ring-1 ring-inset ring-orange-600/20 dark:ring-orange-400/30 cursor-help">
    <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd"/>
    </svg>
    retry {{ $attempts }}
    <x-slot:tip>
        @if($context === 'completed')
            <span class="block font-medium text-white">Retried run</span>
            <span class="mt-1 block text-gray-300">This job failed or was released back to the queue {{ $priorAttempts }} {{ $priorWord }} before it eventually completed.</span>
            <span class="mt-1 block text-gray-400">Source: <span class="font-mono">$job-&gt;attempts()</span> at the time of the successful run.</span>
        @else
            <span class="block font-medium text-white">Retry pickup</span>
            <span class="mt-1 block text-gray-300">Attempt <span class="font-mono">{{ $attempts }}</span> — this job has failed or been released back to the queue {{ $priorAttempts }} {{ $priorWord }} before the current run.</span>
            <span class="mt-1 block text-gray-400">Source: <span class="font-mono">$job-&gt;attempts()</span> stamped on the pending hash at <span class="font-mono">JobProcessing</span>.</span>
        @endif
    </x-slot:tip>
</x-queue-insights::hint>
