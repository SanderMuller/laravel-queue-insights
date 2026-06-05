@php
    /**
     * Shared 5-field filter toolbar used by both Recent completed and Recent failed.
     * Always-visible inline row — no <details> collapse.
     *
     * @var bool $active
     * @var array{connection: string, queue: string, class: string, from: string, to: string} $models  wire:model property names per field
     * @var string $clearMethod  Livewire method invoked by the Clear filter button
     * @var list<string> $connectionOptions
     * @var list<string> $queueOptions
     * @var list<string> $classOptions
     * @var ?string $silenceModel  Failed-pane only: wire:model name for the
     *      "Show silenced" toggle. Null/unset on the completed pane so the
     *      checkbox doesn't render there.
     */
    $silenceModel = $silenceModel ?? null;
    $fieldClass = 'h-9 rounded-lg border-0 bg-white dark:bg-gray-900 px-2.5 text-sm text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500';
@endphp
<div class="mb-4 flex flex-wrap items-end gap-2 text-sm">
    {{-- When the dashboard is scoped to a single connection,
         FilterOptionsBuilder returns an empty connectionOptions list and
         this block hides — the connection axis is already pinned by scope
         and a redundant dropdown would mislead operators. --}}
    @if($connectionOptions !== [])
    <label class="flex flex-col gap-1 font-medium text-gray-500 dark:text-gray-300">
        Connection
        <select wire:model.live="{{ $models['connection'] }}" class="{{ $fieldClass }}">
            <option value="">any</option>
            @foreach($connectionOptions as $opt)
                <option value="{{ $opt }}">{{ $opt }}</option>
            @endforeach
        </select>
    </label>
    @endif
    <label class="flex flex-col gap-1 font-medium text-gray-500 dark:text-gray-300">
        Queue
        {{-- Capped like the Class select below: queue options come straight
             from snapshots[].queue, which on Vapor are full SQS URLs. --}}
        <select wire:model.live="{{ $models['queue'] }}" class="{{ $fieldClass }} max-w-[18rem] truncate">
            <option value="">any</option>
            @foreach($queueOptions as $opt)
                <option value="{{ $opt }}">{{ $opt }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col gap-1 font-medium text-gray-500 dark:text-gray-300">
        Class
        {{-- max-w + truncate caps the control: a native <select> auto-widens
             to its longest option, and queued-closure labels (Closure plus a
             file:line) can be very long — without the cap the filter row
             blows out. The closed control ellipsises; the open native list
             still shows each option in full. --}}
        <select wire:model.live="{{ $models['class'] }}" class="{{ $fieldClass }} max-w-[18rem] truncate font-mono">
            <option value="">any</option>
            @foreach($classOptions as $opt)
                <option value="{{ $opt }}">{{ $opt }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col gap-1 font-medium text-gray-500 dark:text-gray-300">
        From
        <input type="date" wire:model.live="{{ $models['from'] }}" class="{{ $fieldClass }}"/>
    </label>
    <label class="flex flex-col gap-1 font-medium text-gray-500 dark:text-gray-300">
        To
        <input type="date" wire:model.live="{{ $models['to'] }}" class="{{ $fieldClass }}"/>
    </label>

    @if($silenceModel !== null)
        <label class="inline-flex h-9 items-center gap-2 self-end font-medium text-gray-500 dark:text-gray-300">
            <input type="checkbox" wire:model.live="{{ $silenceModel }}"
                   class="rounded border-gray-300 text-emerald-600 dark:text-emerald-400 focus:ring-emerald-500"/>
            Show silenced
        </label>
    @endif

    <div class="ml-auto flex items-center gap-2 self-end">
        @if($active)
            <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-400/30">filtered</span>
            <button type="button" wire:click="{{ $clearMethod }}"
                    class="h-9 rounded-lg bg-white dark:bg-gray-900 px-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-gray-100 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                Clear filter
            </button>
        @endif
    </div>
</div>
