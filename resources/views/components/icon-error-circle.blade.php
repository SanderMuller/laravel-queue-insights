{{--
Inline error-circle icon (circle with `!`). Used by error / failure
hints (typically red tone). Pass class for size + tone (defaults size-4
+ currentColor).
--}}
<svg {{ $attributes->merge(['class' => 'size-4', 'viewBox' => '0 0 20 20', 'fill' => 'currentColor', 'aria-hidden' => 'true']) }}>
    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 1 1 1.5 0v4a.75.75 0 1 1-1.5 0v-4Zm.75 8.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" clip-rule="evenodd"/>
</svg>
