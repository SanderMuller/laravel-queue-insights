# Getting started

The common case: you want to see what your queue is actually doing, per job class, right now.

Everything the dashboard shows is collected the moment the package is installed — nothing to instrument, no jobs to change. Two steps stand between a fresh install and a working view.

**Authorize yourself.** The dashboard route is gated, so define the Gate in a service provider:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewQueueInsights', fn ($user) => $user->isAdmin());
```

**Run a worker, and give it something to do.** Metrics come from jobs that actually run:

```bash
php artisan queue:work
```

Then open `/queue-insights`. Each job class carries its throughput, its duration spread, its failure count, and the live depth of the queue it runs on.

The number to look at first is **depth against throughput**. A queue whose depth climbs while throughput stays flat is not keeping up, and the job class holding the longest durations is usually why.

## Next

- [Dashboard](05-dashboard.md): scoping by connection, the panels, and what each metric counts.
- [Jobs, batches and chains](06-jobs-batches-chains.md): how the three are tracked, and what a chain reports.
- [Alerting](11-alerting.md): turn a threshold into a notification rather than watching a page.
- [Payload capture](04-payload-capture.md): off by default, and worth reading before turning it on.
