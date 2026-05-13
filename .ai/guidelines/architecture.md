# Architecture — subsystem index

Detailed AI-facing internals docs live in `.ai/docs/`. They are **not** inlined
into CLAUDE.md — read the relevant file on demand before editing the listed
files / behaviours. Each doc owns its subsystem's touchpoints, key catalogue,
behavioural rules, and "what NOT to do" list.

| Doc | Read before touching |
|---|---|
| [`.ai/docs/alerting.md`](../docs/alerting.md) | `src/Alerts/**`, detectors, `IssueDispatcher`, `Cooldown`, notification routing, silenced jobs, `config/queue-insights.php` `alerts.*` |
| [`.ai/docs/chain-lineage.md`](../docs/chain-lineage.md) | `RecordJobProcessing`/`RecordJobQueued`/`RecordJobProcessed`/`RecordJobFailed` listeners, `Support/ChainLineage*`, `Support/ParentClassResolver`, `Support/RowEnricher`, chain-lineage modal partials |
| [`.ai/docs/dashboard-dark-mode.md`](../docs/dashboard-dark-mode.md) | `resources/views/layouts/app.blade.php` head script, `theme-toggle` component, any blade adding new surfaces (token pair-check guard fires in CI) |
| [`.ai/docs/prometheus.md`](../docs/prometheus.md) | `src/Prometheus/**`, monotonic-counter listener writes, `/metrics` route + middleware, push command, `config/queue-insights.php` `prometheus.*` |
| [`.ai/docs/worker-command.md`](../docs/worker-command.md) | `src/Console/QueueInsightsWorkCommand.php`, `WorkerProcessFactory`, `WorkerOutputPrefixer`, signal-forwarding tests, `config/queue-insights.php` `work.*` |

## When unsure which doc applies

Grep `.ai/docs/` for the file path or symbol you're about to change — every
doc lists its touchpoints explicitly.
