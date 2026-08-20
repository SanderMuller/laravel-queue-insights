# Failure context

When a job or scheduled task fails, the package captures a snapshot of the surrounding context so you can debug it without re-running anything:

- **`Context` snapshot** — the visible [Laravel `Context`](https://laravel.com/docs/context) facade at failure time (request id, user id, tenant, trace id — whatever your app puts there, including context added *during* the job). Captured on **any** queue driver.
- **Environment** — worker `host`, `pid`, app `env`, and an optional `release`/deploy identifier.

It shows in the failed-job and scheduled-run modals, and — most usefully — in the **Copy as Markdown** export, so a failure pasted into an AI agent or issue tracker is self-describing ("user 4821, tenant acme, trace abc123, release 2.4.0"). Both the `JobFailedAlert` and scheduler `ScheduledTaskFailed` events also carry the snapshot for host listeners. For scheduled tasks, the **root-cause inner exception** (deepest `getPrevious()`) is captured discretely so it survives stack-trace truncation.

Context **values are redacted by key name** through the same `capture.redact_keys` list as payloads (`password`, `token`, `secret`, `api_?key`, `authorization` by default) before storage — the export is paste-safe. Hidden context is never captured.

```php
// config/queue-insights.php
'failure_context' => [
    'enabled' => env('QUEUE_INSIGHTS_FAILURE_CONTEXT', true),
    'capture_app_context' => true,
    'context_keys' => [],            // [] = all visible keys (sanitized); or restrict to a list
    'capture_environment' => true,
    'release_resolver' => null,      // null → env('APP_VERSION'); a string config-key; or a callable
    'max_value_bytes' => 2048,
    'ttl_seconds' => 604800,
],
```

> [!NOTE]
> Capturing is gated on `failure_context.enabled` (on by default — it's cheap, since failures are rare, and sanitized). Set `QUEUE_INSIGHTS_FAILURE_CONTEXT=false` to disable entirely.

## Sentry deep-link

If your app uses [sentry-laravel](https://github.com/getsentry/sentry-laravel), every dispatched job's payload already carries Sentry's distributed-tracing data (`sentry_trace_parent_data` / `sentry_baggage_data`). Set your Sentry org slug and the failed-job modal renders a **View in Sentry** button — plus a `Sentry:` line in the Markdown export — linking to the Sentry **issue** filtered by that job's trace id:

```php
// config/queue-insights.php
'sentry' => [
    'organization' => env('QUEUE_INSIGHTS_SENTRY_ORG'),          // org slug; null/empty hides the button
    'issue_url_template' => env(
        'QUEUE_INSIGHTS_SENTRY_URL_TEMPLATE',
        'https://{org}.sentry.io/issues/?query=trace:{trace}',
    ),
],
```

The button self-hides unless an org slug is configured and the payload carries a trace id. It links the **issue** stream rather than the performance/trace view, so it resolves even when the trace was sampled out (error capture is independent of `traces_sample_rate`).

**Scheduled-task failures** also show a **View in Sentry** button in the per-run modal when Sentry captured the exception. Because scheduled tasks run outside a distributed trace, the link is keyed by Sentry's event ID rather than a trace ID — no extra config beyond `organization` is needed.

> [!NOTE]
> `organization` is your Sentry org *slug* (the `{slug}` in `{slug}.sentry.io`), not the numeric org id. This is unrelated to the `alerts.channels.sentry` block — that one controls alert delivery; this controls dashboard linking.
