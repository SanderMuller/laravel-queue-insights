@php
    /**
     * Classes pane — per-class 24h aggregates table.
     *
     * Required scope vars:
     *   $classes        — list<array<string, mixed>> from ClassRowsBuilder, includes
     *                     'silenced' bool flag for classes in queue-insights.silenced
     *   $selectedClass  — ?string currently selected FQCN (drives the open-by-default
     *                     state of the <details> + the chip + the "Clear filter" button).
     *
     * Clicking a row calls `selectClass($class)` on the Livewire component, which
     * sets `$selectedClass` and routes the Completed list through the per-class
     * stream — the same drilldown the filter form's Class dropdown uses.
     */
@endphp

<x-queue-insights::job-classes-section
    :classes="$classes"
    :selectedClass="$selectedClass"/>
