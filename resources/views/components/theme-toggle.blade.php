{{-- Tri-state theme toggle — sun / monitor / moon segmented pill in
    the dark header. Pure Alpine; the head script in layouts/app.blade.php
    is the single owner of localStorage / matchMedia / html.dark /
    documentElement.dataset.theme. This component:
      1. Reads the resolved preference from `documentElement.dataset.theme`
         on init (`light` / `dark` / `system`).
      2. Dispatches `qi-theme-change` with the chosen preference on click —
         the head script handles the localStorage write, classList toggle,
         and re-emits `qi-theme-applied` for everyone to mirror.
      3. Listens for `qi-theme-applied` on `window` to re-sync `aria-checked`
         when the OS-pref change handler in the head script flips the
         resolved theme during `system` mode. The event detail is a
         `{ preference, resolved }` object — preference drives toggle UI,
         resolved is the rendered light/dark for downstream listeners
         that need to know what's actually on the page.

    Lifecycle: under wire:navigate, Livewire morphs the <body>; the head
    script in <head> survives, the toggle's Alpine x-data is destroyed
    and re-instantiated on each morph. `x-on:qi-theme-applied.window` is
    Alpine-managed — registered on init, torn down on destroy. No
    duplicate listeners across navigations.

    A11y: tri-state segmented control follows WAI-ARIA APG radiogroup
    pattern: outer role="radiogroup" + per-button role="radio" +
    aria-checked + roving tabindex (only the checked radio is in the
    Tab order; arrows move focus + selection between siblings). --}}

<div role="radiogroup" aria-label="Theme"
     x-data="{
        theme: document.documentElement.dataset.theme || 'system',
        order: ['light', 'system', 'dark'],
        setTheme(t) {
            this.theme = t;
            window.dispatchEvent(new CustomEvent('qi-theme-change', { detail: t }));
        },
        moveBy(delta) {
            const i = this.order.indexOf(this.theme);
            const next = this.order[(i + delta + this.order.length) % this.order.length];
            this.setTheme(next);
            this.$nextTick(() => {
                const el = this.$root.querySelector(`[data-qi-theme-radio='${next}']`);
                if (el) { el.focus(); }
            });
        },
     }"
     x-on:qi-theme-applied.window="theme = ($event.detail && $event.detail.preference) || 'system'"
     x-on:keydown.right.prevent="moveBy(1)"
     x-on:keydown.down.prevent="moveBy(1)"
     x-on:keydown.left.prevent="moveBy(-1)"
     x-on:keydown.up.prevent="moveBy(-1)"
     class="inline-flex items-center gap-0.5 rounded-full bg-gray-950/[0.04] p-0.5 ring-1 ring-inset ring-gray-950/10 dark:bg-white/5 dark:ring-white/10">

    {{-- Light --}}
    <button type="button" role="radio" data-qi-theme-radio="light"
            x-bind:aria-checked="theme === 'light' ? 'true' : 'false'"
            x-bind:tabindex="theme === 'light' ? 0 : -1"
            x-on:click="setTheme('light')"
            x-bind:class="theme === 'light' ? 'bg-gray-950/[0.08] text-gray-900 dark:bg-white/10 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'"
            class="inline-flex size-7 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400"
            aria-label="Light theme"
            data-qi-tip="Light theme"
            data-qi-tip-detail="Always render in light mode">
        {{-- Heroicons 24×24 solid sun. The 20×20 mini variant packs 8 rays
             into a tight viewBox; at the toggle's 14 px (size-3.5) display
             size that reads as "blob with bumps". The 24×24 has longer
             rays + cleaner gaps so the sun stays recognisable at this size. --}}
        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM18.894 6.166a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75ZM17.834 18.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18ZM7.758 17.303a.75.75 0 0 0-1.061-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12ZM6.697 7.757a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 0 0-1.061 1.06l1.59 1.591Z"/>
        </svg>
    </button>

    {{-- System --}}
    <button type="button" role="radio" data-qi-theme-radio="system"
            x-bind:aria-checked="theme === 'system' ? 'true' : 'false'"
            x-bind:tabindex="theme === 'system' ? 0 : -1"
            x-on:click="setTheme('system')"
            x-bind:class="theme === 'system' ? 'bg-gray-950/[0.08] text-gray-900 dark:bg-white/10 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'"
            class="inline-flex size-7 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400"
            aria-label="System theme"
            data-qi-tip="System theme"
            data-qi-tip-detail="Follow your OS preference"
            data-qi-tip-resolve="theme">
        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M2 4.75A2.75 2.75 0 0 1 4.75 2h10.5A2.75 2.75 0 0 1 18 4.75v8.5A2.75 2.75 0 0 1 15.25 16h-3.5l.5 1.5h1.5a.75.75 0 0 1 0 1.5h-7a.75.75 0 0 1 0-1.5h1.5l.5-1.5h-3.5A2.75 2.75 0 0 1 2 13.25v-8.5Zm2.75-1.25a1.25 1.25 0 0 0-1.25 1.25v7.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V4.75a1.25 1.25 0 0 0-1.25-1.25H4.75Z" clip-rule="evenodd"/>
        </svg>
    </button>

    {{-- Dark --}}
    <button type="button" role="radio" data-qi-theme-radio="dark"
            x-bind:aria-checked="theme === 'dark' ? 'true' : 'false'"
            x-bind:tabindex="theme === 'dark' ? 0 : -1"
            x-on:click="setTheme('dark')"
            x-bind:class="theme === 'dark' ? 'bg-gray-950/[0.08] text-gray-900 dark:bg-white/10 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'"
            class="inline-flex size-7 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400"
            aria-label="Dark theme"
            data-qi-tip="Dark theme"
            data-qi-tip-detail="Always render in dark mode">
        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.455 2.004a.75.75 0 0 1 .26.77 7 7 0 0 0 9.487 8.21.75.75 0 0 1 1.022.99A9 9 0 1 1 6.717 1.43a.75.75 0 0 1 .738.575Z" clip-rule="evenodd"/>
        </svg>
    </button>
</div>
