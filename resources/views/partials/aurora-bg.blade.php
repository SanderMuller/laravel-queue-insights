@php
    /**
     * Aurora background layers — emerald radial glows + diagonal shimmer,
     * tuned for both light + dark modes. Drop into any `relative
     * overflow-hidden` container as a sibling of the content.
     *
     * Light mode uses soft emerald-200/100 stops at ~40-60% opacity so the
     * glow reads against white surfaces without competing with content.
     * Dark mode uses emerald-500/15 + emerald-400/10 — the same stops the
     * Aurora header uses — so the chrome carries across modes.
     *
     * Pair with the .qi-aurora-strip class (defined globally in
     * layouts/app.blade.php) for the diagonal sweep animation.
     */
@endphp
<div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true" data-qi-aurora>
    <div class="absolute -left-16 -top-20 size-64 rounded-full bg-emerald-200/10 blur-3xl dark:bg-emerald-500/[0.06]"></div>
    <div class="absolute -right-12 -bottom-24 size-56 rounded-full bg-emerald-100/15 blur-3xl dark:bg-emerald-400/[0.04]"></div>
    <div class="qi-aurora-strip absolute inset-0 opacity-5 dark:opacity-[0.06]"></div>
</div>
