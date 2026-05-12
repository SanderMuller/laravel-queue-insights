{{--
Inline info-circle icon (circle with `i`). Used by informational hints
(typically gray/blue tone). Pass class for size + tone (defaults size-4
+ currentColor).
--}}
<svg {{ $attributes->merge(['class' => 'size-4', 'viewBox' => '0 0 20 20', 'fill' => 'currentColor', 'aria-hidden' => 'true']) }}>
    <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-3.75a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0V7a.75.75 0 0 1 .75-.75ZM10 15a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
</svg>
