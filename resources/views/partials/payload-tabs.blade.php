{{--
    Underline-link Structured / Sanitized JSON tab pair for a job modal's
    payload section. Shared by the completed / failed / pending modals so
    all three render the payload identically.

    Vars:
      $idPrefix       — DOM-id namespace ('qi' | 'qi-failed' | 'qi-pending').
                        Tabs + panels become {prefix}-tab-raw, {prefix}-panel-json, etc.
      $payloadTab     — 'raw' | 'json' — active tab (shared Livewire state).
      $structuredBody — value handed to <x-structured-payload> for the
                        Structured tab (decoded array, or raw string when
                        the body failed to JSON-decode).
      $jsonBody       — decoded array (or raw string) for the Sanitized JSON
                        tab. Encoded lazily inside the json branch so the
                        Structured-tab render doesn't pay for an unused encode.
--}}
<div class="mb-3 flex items-center justify-between gap-3 border-b border-gray-950/10 dark:border-white/10">
    <p class="pb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Payload</p>
    <div class="-mb-px flex items-center gap-4" role="tablist">
        <button type="button"
                role="tab"
                id="{{ $idPrefix }}-tab-raw"
                aria-selected="{{ $payloadTab === 'raw' ? 'true' : 'false' }}"
                aria-controls="{{ $idPrefix }}-panel-raw"
                wire:click="setPayloadTab('raw')"
                class="border-b-2 pb-2 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 {{ $payloadTab === 'raw' ? 'border-emerald-500 text-gray-900 dark:text-gray-100' : 'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' }}">
            Structured
        </button>
        <button type="button"
                role="tab"
                id="{{ $idPrefix }}-tab-json"
                aria-selected="{{ $payloadTab === 'json' ? 'true' : 'false' }}"
                aria-controls="{{ $idPrefix }}-panel-json"
                wire:click="setPayloadTab('json')"
                class="border-b-2 pb-2 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 {{ $payloadTab === 'json' ? 'border-emerald-500 text-gray-900 dark:text-gray-100' : 'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' }}">
            Sanitized JSON
        </button>
    </div>
</div>
@if($payloadTab === 'json')
    @php
        $payloadTabsJson = is_array($jsonBody)
            ? json_encode($jsonBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $jsonBody;
    @endphp
    <pre role="tabpanel"
         id="{{ $idPrefix }}-panel-json"
         aria-labelledby="{{ $idPrefix }}-tab-json"
         data-json-highlight
         class="whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-4 font-mono text-xs leading-5 text-gray-900 dark:bg-gray-800 dark:text-gray-100">{{ $payloadTabsJson }}</pre>
@else
    <div role="tabpanel"
         id="{{ $idPrefix }}-panel-raw"
         aria-labelledby="{{ $idPrefix }}-tab-raw"
         class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
        <x-queue-insights::structured-payload :payload="$structuredBody"/>
    </div>
@endif
