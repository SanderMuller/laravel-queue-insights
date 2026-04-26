@php
    /**
     * Shared 5-field filter form used by both Recent completed and Recent failed.
     *
     * @var bool $active
     * @var array{connection: string, queue: string, class: string, from: string, to: string} $models  wire:model property names per field
     * @var string $clearMethod  Livewire method invoked by the Clear filter button
     * @var list<string> $connectionOptions
     * @var list<string> $queueOptions
     * @var list<string> $classOptions
     */
@endphp
<details class="mb-4 group" @if($active) open @endif>
    <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-950/10 hover:bg-gray-950/5 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
        <span>Filter</span>
        <svg class="size-3 transition-transform group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
        </svg>
    </summary>

    <div class="mt-3 grid grid-cols-1 gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-inset ring-gray-950/5 sm:grid-cols-5">
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">
            Connection
            <select wire:model.live="{{ $models['connection'] }}"
                    class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                <option value="">any</option>
                @foreach($connectionOptions as $opt)
                    <option value="{{ $opt }}">{{ $opt }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">
            Queue
            <select wire:model.live="{{ $models['queue'] }}"
                    class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                <option value="">any</option>
                @foreach($queueOptions as $opt)
                    <option value="{{ $opt }}">{{ $opt }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">
            Class
            <select wire:model.live="{{ $models['class'] }}"
                    class="rounded-md border-0 bg-white px-2 py-1.5 font-mono text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                <option value="">any</option>
                @foreach($classOptions as $opt)
                    <option value="{{ $opt }}">{{ $opt }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">
            From
            <input type="date" wire:model.live="{{ $models['from'] }}"
                   class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">
            To
            <input type="date" wire:model.live="{{ $models['to'] }}"
                   class="rounded-md border-0 bg-white px-2 py-1.5 text-xs text-gray-900 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500"/>
        </label>

        @if($active)
            <div class="sm:col-span-5 -mt-1 flex justify-end">
                <button type="button" wire:click="{{ $clearMethod }}"
                        class="rounded text-xs font-medium text-emerald-700 hover:text-emerald-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                    Clear filter
                </button>
            </div>
        @endif
    </div>
</details>
