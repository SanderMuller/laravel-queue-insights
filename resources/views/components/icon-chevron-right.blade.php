{{--
Inline chevron-right (›) icon. Pagination "next", row drill-in, accordion
caret in collapsed state. Pass class for size + tone (defaults size-4 +
currentColor).
--}}
<svg {{ $attributes->merge(['class' => 'size-4', 'viewBox' => '0 0 20 20', 'fill' => 'currentColor', 'aria-hidden' => 'true']) }}>
    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
</svg>
