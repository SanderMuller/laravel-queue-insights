# Documentation

Self-hosted, driver-agnostic queue observability for Laravel. For a quick overview and installation, see the [main README](https://github.com/SanderMuller/laravel-queue-insights/blob/main/README.md).

## Getting started

- [Why Queue Insights?](01-why-queue-insights.md) — what the package answers that `queue:work` and a `failed_jobs` table do not, and the full feature list
- [Installation](02-installation.md) — requirements, install, the snapshot scheduler, environment knobs
- [Payload capture](03-payload-capture.md) — the three capture modes, the separate pending budget, binding your own sanitizer

## Dashboard

- [Dashboard](04-dashboard.md) — the `viewQueueInsights` Gate, multi-connection scoping, retry permissions and workflow, filtering
- [Jobs, batches, and chains](05-jobs-batches-chains.md) — wait time, the pending inspector, batch progress, chain lineage, job initiator
- [Failure context](06-failure-context.md) — what is captured when a job fails, and the Sentry deep-link
- [Theming and embedding](07-theming-and-embedding.md) — custom row markup, admin-layout embedding, dark mode, the cloud look

## Operations

- [Running workers](08-running-workers.md) — `queue-insights:work`, its non-goals, `shutdown_grace_seconds`
- [Ops runbook](09-ops-runbook.md) — console commands, dashboard signals, driver quirks, key prefixes, Redis Cluster
- [Alerting](10-alerting.md) — the nine detectors, cooldown, notification channels, typed events, silencing

## Integrations

- [Horizon supervisor auto-discovery](11-horizon.md) — supervisor queues and silenced jobs read from your Horizon config
- [Connection aliasing](12-connection-aliasing.md) — collapsing dispatcher/worker connection drift onto a canonical key
- [Prometheus](13-prometheus.md) — the `/metrics` endpoint, the metric catalogue, scheduler families, the push gateway
- [Scheduler observability](14-scheduler.md) — task snapshots, run records, missed and hung detection, retention

## Reference

- [Vapor and Laravel Cloud](15-vapor-and-cloud.md) — what the managed platforms handle for you, and the few queues you list yourself
- [Configuration reference](16-configuration.md) — every key in `config/queue-insights.php`, with defaults and what each one changes
