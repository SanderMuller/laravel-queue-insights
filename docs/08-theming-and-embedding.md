# Theming and embedding

Every list the dashboard renders is a publishable Blade partial, and the layout it
mounts in is yours to replace. The theme toggle (light, dark, system, plus an optional
cloud skin) can be turned off outright. None of it needs a fork of the package.

## Customising row markup

The dashboard's queue, completed, and failed lists are each rendered through a Blade partial, plus a shared filter-form partial. They're publishable — a host that wants to swap a row's columns or restyle the filter chrome can publish the partials and edit them in place without forking the whole `dashboard.blade.php` view:

```bash
php artisan vendor:publish --tag=queue-insights-views
```

| Partial                              | What it renders                                                       |
|--------------------------------------|-----------------------------------------------------------------------|
| `partials/queue-row.blade.php`       | One row in the Queues list (Needs attention + Healthy groups)         |
| `partials/completed-row.blade.php`   | One row in Recent completed                                           |
| `partials/failed-list-row.blade.php` | One row in Recent failed                                              |
| `partials/batch-row.blade.php`       | One row in the Batches section (header + per-item rollup)             |
| `partials/batch-chip.blade.php`      | The small chip rendered on rows that belong to a batch                |
| `partials/filter-form.blade.php`     | The collapsible 5-field filter form (used by both completed + failed) |
| `partials/stat-tile.blade.php`       | One tile in the headline-stats panel beside the throughput sparkline  |

If you only want to override one row layout, leave the others unpublished — Blade will fall back to the package's bundled version for those.

## Embedding the dashboard inside an admin layout

Disable the bundled route and mount the Livewire component yourself:

```php
// config/queue-insights.php
'dashboard' => ['enabled' => false, /* ... */],
```

```blade
{{-- resources/views/admin/queue-insights.blade.php --}}
@extends('admin.layout')

@section('content')
    @livewire('queue-insights-dashboard')
@endsection
```

To embed a connection-scoped view, pass the scope as a mount param:

```blade
@livewire('queue-insights-dashboard', ['connection' => $tenant->queueConnection])
```

The component validates the connection against the configured roster (snapshots + Horizon autodiscovery; 404s on mismatch) and runs `viewQueueInsightsConnection` defensively, same as the bundled route — so this is safe to render in publicly-reachable views.

## Dark mode

The dashboard ships with a tri-state theme toggle (sun / monitor / moon) in the header — `light`, `dark`, and `system` (follows `prefers-color-scheme`, default). The header itself stays Horizon-dark in both modes by design; the rest of the chrome flips between light and dark surfaces.

Persistence lives in `localStorage['qi-theme']`. A blocking inline script in `<head>` resolves the preference before first paint, so there's no flash of incorrect theme. The toggle survives `wire:navigate` morphs without leaking listeners.

```php
'dashboard' => [
    'theme' => [
        // Default true; set to false to revert to the always-light look.
        'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
    ],
    'clock' => [
        // Default true. Tri-state header control (12h / auto / 24h) persisted
        // client-side via localStorage['qi-clock']. `auto` follows browser locale.
        'enabled' => env('QUEUE_INSIGHTS_CLOCK_TOGGLE', true),
    ],
    'redis_memory' => [
        // Default false. Opt-in 7th headline tile summing MEMORY USAGE across
        // every key under `key_prefix`. SCAN cost scales with keyspace size —
        // measure before enabling on multi-thousand-key hosts.
        'enabled' => env('QUEUE_INSIGHTS_REDIS_MEMORY_TILE', false),
        'cache_ttl' => 60,
    ],
],
```

Operators on system-dark hosts (terminal, IDE, Linear) get a coherent dark dashboard; operators on light hosts see the same look they had before. Disable via `QUEUE_INSIGHTS_DARK_MODE=false` in `.env` if needed — the inline script, color-scheme meta, and toggle component all skip emission and the dashboard reverts to the pre-feature always-light rendering.

## Cloud look (light mode)

Light mode is re-skinned with a **Laravel Cloud–inspired** look: a soft sunset-sky gradient backdrop, a frosted translucent header, and floating cards with gently rounded corners. It's a pure CSS skin keyed on `html[data-qi-skin="cloud"]:not(.dark)` — **dark mode is completely untouched** (the `:not(.dark)` guard), and the light/dark/system toggle is unchanged. The skin rides whatever renders light (including always-light hosts that disabled the toggle).

```php
// config/queue-insights.php
'dashboard' => [
    'theme' => [
        'enabled' => env('QUEUE_INSIGHTS_DARK_MODE', true),
        'cloud_enabled' => env('QUEUE_INSIGHTS_CLOUD_THEME', true),
    ],
],
```

Prefer the plain flat-light look? Set `QUEUE_INSIGHTS_CLOUD_THEME=false` — the `data-qi-skin` marker and the skin's CSS are then never emitted (zero extra bytes), and light mode renders exactly as before.
