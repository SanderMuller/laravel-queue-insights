# Architecture — subsystem index

Detailed AI-facing internals docs live in `.ai/docs/`. They are **not** inlined
into CLAUDE.md — read the relevant file on demand before editing the listed
files / behaviours. Each doc owns its subsystem's touchpoints, key catalogue,
behavioural rules, and "what NOT to do" list.

**End-user documentation lives in `docs/`**, a VitePress site published to
<https://sandermuller.github.io/laravel-queue-insights/>. `README.md` is a thin
landing page — new user-facing behaviour is documented on a `docs/` page, not by
growing the README. See [`.ai/docs/docs-site.md`](../docs/docs-site.md) before
touching either.

| Doc | Read before touching |
|---|---|
| [`.ai/docs/alerting.md`](../docs/alerting.md) | `src/Alerts/**`, detectors, `IssueDispatcher`, `Cooldown`, notification routing, silenced jobs, `config/queue-insights.php` `alerts.*` |
| [`.ai/docs/chain-lineage.md`](../docs/chain-lineage.md) | `RecordJobProcessing`/`RecordJobQueued`/`RecordJobProcessed`/`RecordJobFailed` listeners, `Support/ChainLineage*`, `Support/ParentClassResolver`, `Support/RowEnricher`, chain-lineage modal partials |
| [`.ai/docs/connection-aliases.md`](../docs/connection-aliases.md) | `src/Support/ConnectionAlias.php`, `src/Support/KeyPrefix.php` (`classKey` + `queueKey`), `Record*` listeners' `$event->connectionName` paths, `ConfigValidator::validateConnectionAliases`, drift detector |
| [`.ai/docs/dashboard-dark-mode.md`](../docs/dashboard-dark-mode.md) | `resources/views/layouts/app.blade.php` head script, `theme-toggle` component, any blade adding new surfaces (token pair-check guard fires in CI) |
| [`.ai/docs/docs-site.md`](../docs/docs-site.md) | `docs/**`, `docs/.vitepress/pages.ts`, `README.md`'s pitch/index sections, `.github/workflows/docs.yml`, the `/docs` entries in `.gitignore` / `.gitattributes` — read before adding, reordering, or renaming a documentation page |
| [`.ai/docs/failure-context.md`](../docs/failure-context.md) | `src/Support/FailureContextCollector.php`, `FailureContextStore.php`, `KeyRedacter.php`, `FailureContextConfigValidator.php`, `RecordJobFailed`/`RecordScheduledTaskFailed` capture paths, `RunStore::stampFailureContext`, `failure-context-section` partial, `config/queue-insights.php` `failure_context.*` |
| [`.ai/docs/horizon-integration.md`](../docs/horizon-integration.md) | `src/Support/HorizonQueueDiscovery.php`, `src/Support/ConfiguredQueueList.php`, `src/Support/SilencedJobs.php`, `QueueInsights::configuredQueues`, `ConfigValidator::validateHorizon`, anything reading `horizon.environments` / `horizon.defaults` / `horizon.silenced` |
| [`.ai/docs/prometheus.md`](../docs/prometheus.md) | `src/Prometheus/**`, monotonic-counter listener writes, `/metrics` route + middleware, push command, `config/queue-insights.php` `prometheus.*` |
| [`.ai/docs/queue-name-canonicalisation.md`](../docs/queue-name-canonicalisation.md) | `src/Support/SqsQueueName.php`, `src/Support/CanonicalQueueKey.php`, `src/Drivers/QueueSnapshotDriverFactory.php` (`makeCloud` / `sqsFromConfig`), `src/Drivers/SqsSnapshotDriver.php`, `RowEnricher::failed`, `QueueScopeKey::decompose`, `QueueInsights::applyFailedJobFilters`, anything canonicalising a queue value that came off a job or a `failed_jobs` row |
| [`.ai/docs/redis-cluster.md`](../docs/redis-cluster.md) | `src/Support/KeyPrefix.php` (`make` hash-tag), `src/Support/RedisPipeline.php`, `src/Support/EagerCommandCollector.php`, `src/Support/RedisEval.php`, `config/queue-insights.php` `redis_cluster`, the `test-cluster` CI job + `cluster` test group |
| [`.ai/docs/worker-command.md`](../docs/worker-command.md) | `src/Console/QueueInsightsWorkCommand.php`, `WorkerProcessFactory`, `WorkerOutputPrefixer`, signal-forwarding tests, `config/queue-insights.php` `work.*` |

## When unsure which doc applies

Grep `.ai/docs/` for the file path or symbol you're about to change — every
doc lists its touchpoints explicitly.
