# Installation

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- Redis (for insights storage)
- `livewire/livewire` 3 or 4 (only if you use the bundled dashboard route).

CI runs against three Livewire resolver legs: Livewire 3.0, Livewire 3 latest, and Livewire 4 latest. Coverage is PHP-side only. The JS and Alpine paths aren't browser-tested, so do a smoke render in your own staging before upgrading the host.

## Install

```bash
composer require sandermuller/laravel-queue-insights
php artisan vendor:publish --tag=queue-insights-config
```

The service provider auto-discovers.

### Run the scheduler

Every dashboard tile, alert detector, and Prometheus gauge reads from snapshots written by `php artisan queue-insights:snapshot`. The package auto-registers it on Laravel's scheduler with `->everyMinute()->withoutOverlapping()`. You just need a host that runs `php artisan schedule:work` (or the equivalent `* * * * * cd /path && php artisan schedule:run` cron).

To opt out and wire it yourself, set `queue-insights.schedule.enabled = false` and add `Schedule::command('queue-insights:snapshot')` to your own kernel.

`snapshots[]` lists the queues to capture. Static config plus Horizon autodiscovery (when `laravel/horizon` is installed) cover most setups, see the published `config/queue-insights.php` for the shape and [Horizon supervisor auto-discovery](12-horizon.md).

### Optional environment knobs

Most hosts only set these two at install time; everything else lives in the
[configuration reference](17-configuration.md).

| Var                            | Default | Purpose                                                                                      |
|--------------------------------|---------|----------------------------------------------------------------------------------------------|
| `QUEUE_INSIGHTS_REDIS`         | `default` | Laravel Redis connection name the package writes to. Point at a dedicated DB on shared Redis. |
| `QUEUE_INSIGHTS_KEY_PREFIX`    | `qm:{APP_ENV}:` | Prefix for every Redis key the package writes. See [Key-prefix strategies](10-ops-runbook.md#key-prefix-strategies). |

Subsystems each carry their own `.enabled` switch (`dashboard.enabled`, `pending.enabled`, `alerts.enabled`, `prometheus.enabled`, `scheduler.enabled`, `batches.enabled`, `initiator.enabled`), flip those individually rather than reaching for a global kill switch.
