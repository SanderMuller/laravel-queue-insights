@props([
    /**
     * Source value: Carbon, DateTimeInterface, ISO-8601 string, or unix
     * timestamp (int / numeric string). null is allowed and renders the
     * `empty` slot if provided, else an em dash.
     */
    'at' => null,
    /**
     * Display mode:
     * - `relative` (default) — "2 minutes ago" / `diffForHumans()`
     * - `relative-short` — `diffForHumans(['short' => true])`
     * - `absolute` — "Apr 14, 2026, 6:45 AM" in user's local TZ (JS-hydrated)
     * - `absolute-mono` — same as `absolute` but rendered in mono font for
     *   the metadata-grid layouts that previously dumped a raw ISO string.
     */
    'format' => 'relative',
    /** Optional prefix word ("started", "queued", "runs", …). */
    'prefix' => null,
    /** Optional fallback text when `$at` is null/unparseable. */
    'empty' => '—',
])

@php
    use Carbon\CarbonInterface;
    use Illuminate\Support\Facades\Date;

    $carbon = null;
    if ($at instanceof CarbonInterface) {
        $carbon = $at;
    } elseif ($at instanceof \DateTimeInterface) {
        $carbon = Date::instance($at);
    } elseif (is_int($at) || (is_string($at) && ctype_digit($at))) {
        // Floor at > 0 — `0` / `"0"` would otherwise render the unix epoch
        // (1970-01-01), almost always wrong for a missing timestamp.
        $intTs = (int) $at;
        if ($intTs > 0) {
            // Auto-detect ms-scale timestamps. Anything >= 10^12 in unix-
            // seconds would be year 33658 (impossible for real data); ms
            // since epoch crosses 10^12 at year 2001. The schedule
            // subsystem stores started_at / finished_at / snapshot:at in
            // ms, so this lets the same component accept either unit
            // without a per-call-site `:ms` flag.
            if ($intTs >= 1_000_000_000_000) {
                $intTs = (int) ($intTs / 1000);
            }
            try { $carbon = Date::createFromTimestamp($intTs); } catch (\Throwable) { $carbon = null; }
        }
    } elseif (is_string($at) && $at !== '') {
        try { $carbon = Date::parse($at); } catch (\Throwable) { $carbon = null; }
    }

    if ($carbon === null) {
        $emptyText = trim(($prefix ? $prefix . ' ' : '') . $empty);
    } else {
        // diffForHumans compares underlying timestamps, so it is timezone-
        // invariant — call BEFORE the UTC mutation below so the original
        // local-TZ Carbon doesn't need a copy().
        $relative = match ($format) {
            'relative-short' => $carbon->diffForHumans(['short' => true]),
            'absolute', 'absolute-mono' => null,
            default => $carbon->diffForHumans(),
        };
        // datetime= MUST be UTC ISO so JS hydration parses unambiguously.
        // Mutate in place: Carbon::copy() costs ~200μs per call and this
        // component is invoked dozens of times per dashboard render.
        $carbon->utc();
        $isoUtc = $carbon->toIso8601String();
        $absUtc = $carbon->format('M j, Y, g:i A');
        // Server-side fallback for absolute formats renders in UTC with an
        // explicit `UTC` suffix — guarantees the no-JS path (and the brief
        // pre-hydration paint frame) is never ambiguously labelled in the
        // server's local timezone. JS overwrites with the user's local TZ.
        $display = $relative ?? ($absUtc . ' UTC');
        if ($prefix !== null && $prefix !== '') {
            $display = $prefix . ' ' . $display;
        }
    }

    $extraClass = match ($format) {
        'absolute-mono' => 'font-mono',
        default => '',
    };
@endphp

@if ($carbon === null)
    {{-- Apply $extraClass to the fallback too so a column with mixed
         null/non-null absolute-mono entries keeps consistent typography. --}}
    <span {{ $attributes->class($extraClass) }}>{{ $emptyText }}</span>
@else
    {{-- aria-label carries the unambiguous UTC absolute string so screen
         readers announce something meaningful (the JS tooltip itself is
         visual-only). JS keeps this in sync if it rewrites the text. --}}
    <time {{ $attributes->class($extraClass) }}
          datetime="{{ $isoUtc }}"
          data-qi-time
          data-qi-time-format="{{ $format }}"
          @if($prefix !== null && $prefix !== '') data-qi-time-prefix="{{ $prefix }}" @endif
          aria-label="{{ ($prefix !== null && $prefix !== '' ? $prefix . ' ' : '') . $absUtc . ' UTC' }}">{{ $display }}</time>
@endif
