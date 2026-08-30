---
layout: home

hero:
  name: Queue Insights
  text: See what your queues are actually doing
  tagline: Self-hosted, driver-agnostic queue observability for Laravel. Depth, wait time, throughput, batches, chains, failures and scheduler runs, across SQS, Redis and database queues, with no external service.
  image:
    src: /logo.svg
    alt: Queue Insights
  actions:
    - theme: brand
      text: Why Queue Insights?
      link: /why-queue-insights
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: Getting started
      link: /getting-started
    - theme: alt
      text: Live demo
      link: https://queue-insights-demo.laravel.cloud

features:
  - title: Driver-agnostic
    details: Depth, in-flight and delayed counts per queue on SQS, Redis and database, in the same view whichever driver a connection runs. No Horizon requirement.
    link: /why-queue-insights
  - title: Wait time, not just runtime
    details: The enqueue-to-pickup gap per queue (p50 / p95) and per job, so a backlog shows up as a slow queue before it shows up as a failed one.
    link: /jobs-batches-chains#wait-time
  - title: Batches and chains
    details: Per-batch progress and counts backed by Laravel's own batch records, a Next chip on chained jobs, and opportunistic backward lineage to the parent.
    link: /jobs-batches-chains#batches
  - title: Alerting with nine detectors
    details: Depth, stalled, oldest-pending, stuck-inflight, failure-rate, slow-p95, snapshot-errored, backlog-growing, and connection-drift, with per-rule cooldown and log / Slack / mail / Sentry channels.
    link: /alerting
  - title: Prometheus and Grafana
    details: An opt-in /metrics endpoint in text and OpenMetrics, fail-closed auth, per-class cardinality control, and a push command for short-lived workers.
    link: /prometheus
  - title: Scheduler observability
    details: Every Scheduled* event captured into per-task snapshots and per-run records, with missed and hung detection routed through the same alert pipeline.
    link: /scheduler
---

## Install it in two commands

```bash
composer require sandermuller/laravel-queue-insights
php artisan vendor:publish --tag=queue-insights-config
```

The dashboard mounts at `/queue-insights` behind a `viewQueueInsights` Gate you define. Every tile,
detector, and gauge reads from snapshots written by `queue-insights:snapshot`, which the package
registers on Laravel's scheduler for you, so a host running `schedule:work` is the only other
requirement.

The dashboard is Livewire and Blade with no Filament or Nova coupling. The Redis keyspace it
writes is bounded and evicts itself, and it captures no job payloads until you turn capture on.

## Where to next

<HomeNextSteps />
