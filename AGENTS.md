<package-boost-guidelines>
# Package Boost Guidelines

These guidelines replace Laravel Boost's default foundation for
repositories that ship as Composer packages — Laravel-targeted or
framework-agnostic. The framing, tooling, and trade-offs differ from
application development; follow this version when working inside a
package codebase.

## Foundational Context

This codebase is a **Composer package**, not an application. The rules
below hold regardless of which framework (if any) the package targets.

- There is no `app/`, `bootstrap/`, `routes/`, `.env`, or database by
  default. Tooling that assumes an application context (e.g. running
  `php artisan` against the package itself) does not apply.
- The primary artefact is the package's public API — entry-point
  classes, service providers, exposed contracts. Everything else is
  scaffolding.
- Downstream consumers depend on this package via Composer. Every
  public change is a user-facing API change governed by semver.
- `composer.json` is the source of truth for supported PHP versions
  and any framework constraints. Check `require.php` (and any
  `require.<framework>/*` entries) before using version-specific
  features.

## Source Layout

- `src/` — package source, PSR-4 autoloaded per `composer.json`
- `tests/` — Pest or PHPUnit suite
- `config/` — publishable defaults shipped with the package, when
  applicable
- `resources/` — views, translations, Boost skills / guidelines, when
  applicable
- `database/migrations`, `database/factories` — only if the package
  ships them
- `workbench/` — developer-only Testbench scaffolding when Testbench
  is in use; never shipped

Check sibling files before inventing structure. Do not introduce new
top-level directories without a clear reason.

## Tests Are the Specification

The package has no running application to click through. Tests are how
behaviour is pinned down.

- Write tests alongside any behavioural change.
- Do not create "verification scripts" when a test can prove the same
  thing.
- Run the project's configured test runner (`vendor/bin/pest` or
  `vendor/bin/phpunit`) before claiming a change is done.

## Public API Discipline

- Every `public`, `protected`, or exported symbol is part of the
  package's surface. Breaking changes require a major version bump.
- Prefer `final` classes and `private`/`@internal` markers for
  anything not intended for extension.
- Keep config keys, published asset paths, and service container
  bindings stable across patch and minor versions.

## Conventions

- Match existing code style, naming, and structural patterns — check
  sibling files before writing new ones.
- Use descriptive names (`resolvePublishDestination`, not `resolve()`).
- Reuse existing helpers before adding new ones.
- Do not add dependencies without approval; every new `require` is a
  constraint downstream consumers inherit.

## Documentation Files

Only create or edit documentation (README, CHANGELOG, docs/) when
explicitly requested or when a behaviour change requires it.

## Replies

Be concise. Focus on what changed and why. Skip restating what the
diff already shows.

## If your package targets Laravel

The rest of this document is Laravel-specific. Skip it if the package
is framework-agnostic — `composer.json` should make that obvious (no
`require.illuminate/*`, no `require.laravel/framework`).

### Laravel context

A Testbench-provided Laravel application is spun up only at test
time. Base test case is `Orchestra\Testbench\TestCase`.
`composer.json`'s `require.illuminate/*` (or
`require.laravel/framework`) defines the supported Laravel range —
check it before using version-specific framework APIs.

### Use `vendor/bin/testbench`, not `php artisan`

Running artisan commands directly against the package fails — there is
no host application. Use Testbench's binary:

| Instead of | Use |
|---|---|
| `php artisan test` | `vendor/bin/pest` or `vendor/bin/phpunit` |
| `php artisan tinker` | `vendor/bin/testbench tinker` |
| `php artisan make:*` | Create files manually under `src/` |
| `php artisan vendor:publish` | `vendor/bin/testbench vendor:publish` |

#### Commands that require `laravel/boost`

These only apply when the package has `laravel/boost` as a dev
dependency. Skip if Boost isn't installed — `package-boost:sync`
prints a warning and moves on.

| Instead of | Use |
|---|---|
| `php artisan boost:install` | `vendor/bin/testbench boost:install` |
| `php artisan boost:mcp` | `vendor/bin/testbench boost:mcp` |

Register the package's service provider in `testbench.yaml` under
`providers:` so Testbench boots it. Published files land in
`workbench/` by default, not `config/` or `resources/` of a host app.

### Cross-Version Compatibility

Supporting multiple Laravel / PHP majors is routine for Laravel
packages. Activate `cross-version-laravel-support` **before** writing
the code; activate `ci-matrix-troubleshooting` **after** a matrix cell
has failed.

---

# Architecture — subsystem index

Detailed AI-facing internals docs live in `.ai/docs/`. They are **not** inlined
into CLAUDE.md — read the relevant file on demand before editing the listed
files / behaviours. Each doc owns its subsystem's touchpoints, key catalogue,
behavioural rules, and "what NOT to do" list.

| Doc | Read before touching |
|---|---|
| [`.ai/docs/alerting.md`](../docs/alerting.md) | `src/Alerts/**`, detectors, `IssueDispatcher`, `Cooldown`, notification routing, silenced jobs, `config/queue-insights.php` `alerts.*` |
| [`.ai/docs/chain-lineage.md`](../docs/chain-lineage.md) | `RecordJobProcessing`/`RecordJobQueued`/`RecordJobProcessed`/`RecordJobFailed` listeners, `Support/ChainLineage*`, `Support/ParentClassResolver`, `Support/RowEnricher`, chain-lineage modal partials |
| [`.ai/docs/connection-aliases.md`](../docs/connection-aliases.md) | `src/Support/ConnectionAlias.php`, `src/Support/KeyPrefix.php` (`classKey` + `queueKey`), `Record*` listeners' `$event->connectionName` paths, `ConfigValidator::validateConnectionAliases`, drift detector |
| [`.ai/docs/dashboard-dark-mode.md`](../docs/dashboard-dark-mode.md) | `resources/views/layouts/app.blade.php` head script, `theme-toggle` component, any blade adding new surfaces (token pair-check guard fires in CI) |
| [`.ai/docs/horizon-integration.md`](../docs/horizon-integration.md) | `src/Support/HorizonQueueDiscovery.php`, `src/Support/ConfiguredQueueList.php`, `src/Support/SilencedJobs.php`, `QueueInsights::configuredQueues`, `ConfigValidator::validateHorizon`, anything reading `horizon.environments` / `horizon.defaults` / `horizon.silenced` |
| [`.ai/docs/prometheus.md`](../docs/prometheus.md) | `src/Prometheus/**`, monotonic-counter listener writes, `/metrics` route + middleware, push command, `config/queue-insights.php` `prometheus.*` |
| [`.ai/docs/worker-command.md`](../docs/worker-command.md) | `src/Console/QueueInsightsWorkCommand.php`, `WorkerProcessFactory`, `WorkerOutputPrefixer`, signal-forwarding tests, `config/queue-insights.php` `work.*` |

## When unsure which doc applies

Grep `.ai/docs/` for the file path or symbol you're about to change — every
doc lists its touchpoints explicitly.

# Release Automation

## CHANGELOG.md is updated automatically — do NOT edit by hand for releases

`CHANGELOG.md` is kept in sync with GitHub releases by `.github/workflows/update-changelog.yml`. When a release is published (not just drafted), the workflow uses `stefanzweifel/changelog-updater-action` to prepend the release body to `CHANGELOG.md` and commits the update back to `main`.

This means:

- **Do not** add changelog entries manually when preparing a release. The release body (drafted in `internal/release-notes-<version>.md` and pasted into the GitHub release) becomes the changelog entry automatically.
- **Do not** include a changelog diff in the release PR — the post-release commit comes from CI.
- If the changelog needs a fix *after* a release, edit `CHANGELOG.md` directly and commit — but this is unusual and only for typos or formatting issues in the auto-generated entry.

## Benchmark table in release body is updated automatically

`.github/workflows/release-benchmark.yml` appends the latest benchmark table between the `<!-- benchmark-start -->` / `<!-- benchmark-end -->` markers in the release body after publish. Do not paste benchmark numbers manually into the release body with those markers — write the narrative above and let CI fill in the table.

## Release workflow (summary)

1. Draft release notes in `internal/release-notes-<version>.md`
2. Commit and push code + notes file to `main`
3. Tag and create the GitHub release with the release-notes file as the body
4. CI automatically:
   - Appends the benchmark table to the release body
   - Prepends the release body to `CHANGELOG.md` and commits it back to `main`

No manual `CHANGELOG.md` edits are part of the release PR.

## Verification Before Completion

Before claiming any work is complete or successful, run the verification command fresh and confirm the output. Evidence before claims, always.

### Required Before Any Completion Claim

1. **Run** the relevant command (in the current message, not from memory)
2. **Read** the full output
3. **Confirm** it supports the claim
4. **Then** state the result with evidence

### During Development (after each change)

| Claim            | Required verification                              |
|------------------|----------------------------------------------------|
| Code style clean | `vendor/bin/pint --dirty --format agent` output    |
| Tests pass       | Related tests pass via `--filter` or specific file |
| Bug fixed        | Previously failing test now passes                 |

### At Completion Only (feature/phase done, before PR)

These are slow checks — only run them once at the very end:

| Claim             | Required verification                                           |
|-------------------|-----------------------------------------------------------------|
| Rector ran clean  | `vendor/bin/rector process` showing 0 changes                   |
| PHPStan clean     | `vendor/bin/phpstan analyse --memory-limit=2G` showing 0 errors |
| Full suite passes | `vendor/bin/pest` output showing 0 failures                     |
| Feature complete  | All above checks pass                                           |

### Always Capture Command Output

Append `|| true` to all verification commands (tests, linting, type checks) so the output is always captured, even on failure. Without it, a non-zero exit code can hide the output, forcing an expensive second run just to read the errors.

```bash
# CORRECT — output always visible
vendor/bin/pest --filter=testName || true
vendor/bin/pint --dirty --format agent || true

# WRONG — output lost on failure, wastes time re-running
vendor/bin/pest --filter=testName
```

### Never Use Without Evidence

- "should work now"
- "that should fix it"
- "looks correct"
- "I'm confident this works"

These phrases indicate missing verification. Run the command first, then report what actually happened.
</package-boost-guidelines>
