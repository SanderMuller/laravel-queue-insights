# Documentation

Self-hosted, driver-agnostic queue observability for Laravel. For a quick overview and installation, see the [main README](https://github.com/SanderMuller/laravel-queue-insights/blob/main/README.md).

## Getting started

- [Why Queue Insights?](01-why-queue-insights.md): what the package answers that `queue:work` and a `failed_jobs` table do not, and the full feature list
- [Installation](02-installation.md): requirements, install, the snapshot scheduler, environment knobs
- [Payload capture](04-payload-capture.md): the three capture modes, the separate pending budget, binding your own sanitizer

## Dashboard

- [Dashboard](05-dashboard.md): the `viewQueueInsights` Gate, multi-connection scoping, retry permissions and workflow, filtering
- [Jobs, batches, and chains](06-jobs-batches-chains.md): wait time, the pending inspector, batch progress, chain lineage, job initiator
- [Failure context](07-failure-context.md): what is captured when a job fails, and the Sentry deep-link
- [Theming and embedding](08-theming-and-embedding.md): custom row markup, admin-layout embedding, dark mode, the cloud look

## Operations

- [Running workers](09-running-workers.md): `queue-insights:work`, its non-goals, `shutdown_grace_seconds`
- [Ops runbook](10-ops-runbook.md): console commands, dashboard signals, driver quirks, key prefixes, Redis Cluster
- [Alerting](11-alerting.md): the nine detectors, cooldown, notification channels, typed events, silencing

## Integrations

- [Horizon supervisor auto-discovery](12-horizon.md): supervisor queues and silenced jobs read from your Horizon config
- [Connection aliasing](13-connection-aliasing.md): collapsing dispatcher/worker connection drift onto a canonical key
- [Prometheus](14-prometheus.md): the `/metrics` endpoint, the metric catalogue, scheduler families, the push gateway
- [Scheduler observability](15-scheduler.md): task snapshots, run records, missed and hung detection, retention

## Reference

- [Vapor and Laravel Cloud](16-vapor-and-cloud.md): what the managed platforms handle for you, and the few queues you list yourself
- [Configuration reference](17-configuration.md): every key in `config/queue-insights.php`, with defaults and what each one changes
