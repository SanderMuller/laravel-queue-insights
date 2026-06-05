@php
    /**
     * Shared filter toolbar (Recent completed + Recent failed) restyled to
     * match the Laravel Cloud search + filter components: clean white
     * dropdowns, a magnifier-prefixed class search backed by a datalist, and a
     * grouped date range — all on the cloud calm tokens, dark-paired.
     *
     * @var bool $active
     * @var array{connection: string, queue: string, class: string, from: string, to: string} $models  wire:model property names per field
     * @var string $clearMethod  Livewire method invoked by the Clear button
     * @var list<string> $connectionOptions
     * @var list<string> $queueOptions
     * @var list<string> $classOptions
     * @var ?string $silenceModel  Failed-pane only: wire:model name for the
     *      "Show silenced" toggle. Null/unset on the completed pane.
     */
    $silenceModel = $silenceModel ?? null;
    // Clean LC dropdown: white, hairline ring, subtle chevron, no stacked label
    // (the selected value names the filter, like LC's "All deployments").
    $selectClass = 'h-9 rounded-lg border-0 bg-white dark:bg-gray-900 px-2.5 text-sm text-gray-900 dark:text-gray-100 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-emerald-500';
    // Datalist id must be unique per pane (filter-form renders on both tabs).
    $classListId = 'qi-classlist-' . \Illuminate\Support\Str::slug($models['class']);
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
    {{-- Class search — the Laravel Cloud "Search" component: a magnifier-
         prefixed input backed by a native datalist of known classes, so typing
         autocompletes and the value drives the (prefix-LIKE) class filter. --}}
    <div class="relative">
        <svg class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd"/>
        </svg>
        <input type="search" list="{{ $classListId }}" wire:model.live.debounce.300ms="{{ $models['class'] }}"
               placeholder="Search class…" aria-label="Search by class"
               class="h-9 w-60 rounded-lg border-0 bg-white pl-8 pr-2.5 text-sm font-mono text-gray-900 ring-1 ring-inset ring-gray-950/10 placeholder:font-sans placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10 dark:placeholder:text-gray-500"/>
        <datalist id="{{ $classListId }}">
            @foreach($classOptions as $opt)
                <option value="{{ $opt }}"></option>
            @endforeach
        </datalist>
    </div>

    {{-- When scoped to a single connection, FilterOptionsBuilder returns an
         empty connectionOptions list and this dropdown hides. --}}
    @if($connectionOptions !== [])
        <select wire:model.live="{{ $models['connection'] }}" aria-label="Filter by connection" class="{{ $selectClass }}">
            <option value="">All connections</option>
            @foreach($connectionOptions as $opt)
                <option value="{{ $opt }}">{{ $opt }}</option>
            @endforeach
        </select>
    @endif

    {{-- Queue options can be full SQS URLs (Vapor); cap the closed width. --}}
    <select wire:model.live="{{ $models['queue'] }}" aria-label="Filter by queue" class="{{ $selectClass }} max-w-[16rem] truncate">
        <option value="">All queues</option>
        @foreach($queueOptions as $opt)
            <option value="{{ $opt }}">{{ $opt }}</option>
        @endforeach
    </select>

    {{-- Date range — a single grouped control (calendar prefix + From–To),
         mirroring LC's date-range chip. --}}
    <div class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-white px-2.5 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
        <svg class="size-4 shrink-0 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1.5 5.5a.75.75 0 0 0 0 1.5h11.5a.75.75 0 0 0 0-1.5H4.25Z" clip-rule="evenodd"/>
        </svg>
        <input type="date" wire:model.live="{{ $models['from'] }}" aria-label="From date" class="border-0 bg-transparent p-0 text-sm text-gray-900 focus:ring-0 dark:text-gray-100"/>
        <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">–</span>
        <input type="date" wire:model.live="{{ $models['to'] }}" aria-label="To date" class="border-0 bg-transparent p-0 text-sm text-gray-900 focus:ring-0 dark:text-gray-100"/>
    </div>

    @if($silenceModel !== null)
        {{-- accent-color styles the NATIVE checkbox emerald — the Play CDN
             ships without @tailwindcss/forms, so text-*/focus:ring-* form
             utilities are inert here. --}}
        <label class="inline-flex h-9 cursor-pointer items-center gap-2 self-center text-sm font-medium text-gray-600 dark:text-gray-300">
            <input type="checkbox" wire:model.live="{{ $silenceModel }}"
                   class="size-4 rounded accent-emerald-600 dark:accent-emerald-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"/>
            Show silenced
        </label>
    @endif

    <div class="ml-auto flex items-center gap-2">
        @if($active)
            <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-300 dark:ring-emerald-400/30">filtered</span>
            <button type="button" wire:click="{{ $clearMethod }}"
                    class="h-9 rounded-lg bg-white dark:bg-gray-900 px-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10 transition hover:bg-gray-950/[0.03] dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-gray-100 focus:ring-2 focus:ring-inset focus:ring-emerald-500">
                Clear
            </button>
        @endif
    </div>
</div>
