{{--
Inline chevron-left (‹) icon. Pagination "prev", chain back-button, modal
breadcrumb. Pass class for size + tone (defaults size-4 + currentColor).
--}}
<svg {{ $attributes->merge(['class' => 'size-4', 'viewBox' => '0 0 20 20', 'fill' => 'currentColor', 'aria-hidden' => 'true']) }}>
    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/>
</svg>
