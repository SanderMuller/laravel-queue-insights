@php
    // Defaults for variables when this partial is included via @include
    // or rendered through view(). @props is component-specific and emits
    // residue under the L11.0/L12.0 Blade compilers, so we use plain
    // defaults instead.
    $parentUuid ??= null;
    $parentClass ??= null;
    $copyId ??= 'qi-parent-uuid';
    $parentTarget ??= null;
    $fromClass ??= null;
@endphp
@if(is_string($parentUuid) && $parentUuid !== '')
    @php
        $hasTarget = is_array($parentTarget) && isset($parentTarget['type'], $parentTarget['id']);
        $jsParentArg = $hasTarget ? \Illuminate\Support\Js::from($parentUuid) : null;
        $jsFromClass = $hasTarget ? \Illuminate\Support\Js::from($fromClass) : null;
    @endphp
    <div data-section="parent-lineage" class="mt-3 rounded-xl bg-white p-4 ring-1 ring-gray-950/5">
        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500">From</p>
        @if($hasTarget)
            {{-- Whole row is the click target — opens the parent's modal
                via the `openByUuid` action which dispatches to
                openPayload / openFailed / openPending based on where the
                parent currently lives (UuidResolver). --}}
            <button type="button"
                    wire:click="openByUuid({{ $jsParentArg }}, {{ $jsFromClass }})"
                    class="mt-2 flex w-full items-start gap-3 rounded-lg p-2 text-left transition hover:bg-emerald-50/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500"
                    aria-label="Open parent job's modal">
                <span class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20" aria-hidden="true">↰</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-mono text-sm text-gray-900">{{ $parentClass ?? '—' }}</span>
                    <span class="mt-0.5 block truncate font-mono text-[11px] text-gray-500">{{ $parentUuid }}</span>
                </span>
                <span class="mt-1 text-[10px] font-medium uppercase tracking-wider text-emerald-700">Open →</span>
            </button>
            <div class="mt-2 flex items-center gap-1.5 text-[11px] text-gray-500">
                <code id="{{ $copyId }}" class="hidden">{{ $parentUuid }}</code>
                <x-queue-insights::copy-button :target="$copyId" label="Copy parent UUID" variant="icon" class="shrink-0"/>
                <span>Copy UUID</span>
            </div>
        @else
            {{-- Aged-out / batches-disabled fallback: plain text + copy
                button, matching the pre-click-through behaviour. --}}
            <dl class="mt-2 space-y-1 text-xs">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <dt aria-hidden="true" class="text-gray-400">↰</dt>
                    <dd class="break-all font-mono text-gray-900">
                        {{ $parentClass ?? '—' }}
                    </dd>
                </div>
                <div class="flex min-w-0 items-center gap-1.5">
                    <dt class="shrink-0 text-gray-400">UUID</dt>
                    <dd class="flex min-w-0 flex-1 items-center gap-1.5">
                        <code id="{{ $copyId }}"
                              class="truncate rounded bg-gray-950/5 px-1.5 py-0.5 font-mono text-[11px] text-gray-600">{{ $parentUuid }}</code>
                        <x-queue-insights::copy-button :target="$copyId" label="Copy parent UUID" variant="icon" class="shrink-0"/>
                    </dd>
                </div>
            </dl>
            <p class="mt-2 text-[11px] text-gray-500">
                Parent job has aged out of retention — its modal is no longer reachable.
            </p>
        @endif
    </div>
@endif
