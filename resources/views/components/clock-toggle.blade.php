{{-- Tri-state clock toggle — 12h / auto / 24h segmented pill in the dark
    header. Mirrors the theme-toggle radiogroup pattern: this component is
    pure Alpine; the head script in layouts/app.blade.php is the single
    owner of `localStorage['qi-clock']`, `documentElement.dataset.clock`,
    and the `qi-clock-applied` event. This component:
      1. Reads the resolved preference from `documentElement.dataset.clock`
         on init (`12h` / `24h` / `auto`).
      2. Dispatches `qi-clock-change` with the chosen preference on click —
         the head script handles the localStorage write and re-emits
         `qi-clock-applied` so the qi-time hydrator rebuilds formatters.
      3. Listens for `qi-clock-applied` on `window` to re-sync `aria-checked`
         after `livewire:navigated` re-applies from localStorage.

    A11y: tri-state segmented control follows WAI-ARIA APG radiogroup
    pattern — same shape as theme-toggle. --}}

<div role="radiogroup" aria-label="Clock format"
     x-data="{
        clock: document.documentElement.dataset.clock || 'auto',
        order: ['12h', 'auto', '24h'],
        setClock(c) {
            this.clock = c;
            window.dispatchEvent(new CustomEvent('qi-clock-change', { detail: c }));
        },
        moveBy(delta) {
            const i = this.order.indexOf(this.clock);
            const next = this.order[(i + delta + this.order.length) % this.order.length];
            this.setClock(next);
            this.$nextTick(() => {
                const el = this.$root.querySelector(`[data-qi-clock-radio='${next}']`);
                if (el) { el.focus(); }
            });
        },
     }"
     x-on:qi-clock-applied.window="clock = ($event.detail && $event.detail.preference) || 'auto'"
     x-on:keydown.right.prevent="moveBy(1)"
     x-on:keydown.down.prevent="moveBy(1)"
     x-on:keydown.left.prevent="moveBy(-1)"
     x-on:keydown.up.prevent="moveBy(-1)"
     class="inline-flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-inset ring-white/10">

    {{-- 12h --}}
    <button type="button" role="radio" data-qi-clock-radio="12h"
            x-bind:aria-checked="clock === '12h' ? 'true' : 'false'"
            x-bind:tabindex="clock === '12h' ? 0 : -1"
            x-on:click="setClock('12h')"
            x-bind:class="clock === '12h' ? 'bg-white/10 text-white' : 'text-gray-400 hover:text-white'"
            class="inline-flex h-7 items-center justify-center rounded-full px-2 text-[10px] font-semibold tracking-wide transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400"
            aria-label="12-hour clock (AM/PM)"
            title="12-hour (AM/PM)">
        12h
    </button>

    {{-- Auto (follow locale) --}}
    <button type="button" role="radio" data-qi-clock-radio="auto"
            x-bind:aria-checked="clock === 'auto' ? 'true' : 'false'"
            x-bind:tabindex="clock === 'auto' ? 0 : -1"
            x-on:click="setClock('auto')"
            x-bind:class="clock === 'auto' ? 'bg-white/10 text-white' : 'text-gray-400 hover:text-white'"
            class="inline-flex size-7 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400"
            aria-label="Auto clock (follow browser locale)"
            title="Auto (browser locale)">
        {{-- Heroicons mini globe — visually communicates "follow locale" --}}
        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.46-14.473a3.5 3.5 0 0 0-.92 0c-.13.018-.36.107-.66.487-.305.385-.62.99-.9 1.808-.273.794-.498 1.747-.65 2.808H12.57c-.152-1.061-.377-2.014-.65-2.808-.28-.818-.595-1.423-.9-1.808-.3-.38-.53-.469-.66-.487ZM6.806 7.13c.18-1.292.46-2.463.812-3.43-1.348.62-2.467 1.65-3.18 2.95.59.213 1.398.385 2.368.48Zm-.13 1.495a18.4 18.4 0 0 0-.001 2.75c-1.213-.103-2.272-.345-3.13-.69a6.55 6.55 0 0 1 0-1.37c.857-.345 1.916-.587 3.13-.69Zm.13 4.245c.18 1.292.46 2.463.812 3.43-1.348-.62-2.467-1.65-3.18-2.95.59-.213 1.398-.385 2.368-.48Zm.527-1.495H12.668a16.98 16.98 0 0 1 0-2.75H7.333a16.98 16.98 0 0 0 0 2.75ZM12.382 16.3c.353-.967.63-2.138.812-3.43.97.095 1.779.267 2.368.48-.713 1.3-1.832 2.33-3.18 2.95Zm.942-4.925c1.213-.103 2.272-.345 3.13-.69a6.55 6.55 0 0 0 0-1.37c-.857-.345-1.916-.587-3.13-.69a18.4 18.4 0 0 1 0 2.75Zm-.13-4.245c.97-.095 1.779-.267 2.368-.48-.713-1.3-1.832-2.33-3.18-2.95.353.967.63 2.138.812 3.43Z" clip-rule="evenodd"/>
        </svg>
    </button>

    {{-- 24h --}}
    <button type="button" role="radio" data-qi-clock-radio="24h"
            x-bind:aria-checked="clock === '24h' ? 'true' : 'false'"
            x-bind:tabindex="clock === '24h' ? 0 : -1"
            x-on:click="setClock('24h')"
            x-bind:class="clock === '24h' ? 'bg-white/10 text-white' : 'text-gray-400 hover:text-white'"
            class="inline-flex h-7 items-center justify-center rounded-full px-2 text-[10px] font-semibold tracking-wide transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400"
            aria-label="24-hour clock"
            title="24-hour">
        24h
    </button>
</div>
