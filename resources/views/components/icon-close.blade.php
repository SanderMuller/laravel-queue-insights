{{--
Inline close (×) icon. Consolidated from 10 inline copies across the
dashboard + modal partials. Accepts standard attribute bag so callers
can override class (size), aria-label, etc.

Default class size-4 matches the dominant existing usage; pass class to
override. aria-hidden="true" is appropriate when the surrounding button
already carries an aria-label.
--}}
<svg {{ $attributes->merge(['class' => 'size-4', 'viewBox' => '0 0 20 20', 'fill' => 'currentColor', 'aria-hidden' => 'true']) }}>
    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
</svg>
