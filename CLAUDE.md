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

---

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

---

## AskUserQuestion Phrasing

When writing an `AskUserQuestion` question, option labels, or option descriptions, **avoid first- and second-person pronouns** — `I`, `me`, `my`, `we`, `our`, `you`, `your`. In that tool the user is reading a question *from* the assistant and answering it, so the roles are inverted and these pronouns are ambiguous: the reader cannot tell whether `I`/`my` means the assistant or themselves, nor whether `you`/`your` means them or the assistant.

Name the actor explicitly instead — "the assistant" (these guidelines are shared across agents, so avoid hard-coding a product name like Claude or Copilot) and "the user" (or a concrete role) for the person answering — or rephrase to drop the pronoun entirely.

```text
❌ "Which approach do you want me to take?"
❌ "Should I keep the existing tests you wrote?"

✅ "Which approach should the assistant take?"
✅ "Keep the existing tests, or replace them?"   (pronoun dropped)
✅ "Should the assistant keep the tests already in the repo?"
```

This applies to every part of the question payload: the `question` text, each option `label`, and each option `description`.

---

## JavaScript & TypeScript

### Control Structures

- Always use curly braces for control structures, even for a single statement.
- Never use single-line `if/return`, `if/break`, or `if/continue` statements.
- Each control-structure statement goes on its own line.

```js
// ❌ WRONG — single-line control structures
if (index === -1) break;
if (! element) return 0;
if (query === '') return;

// ✅ CORRECT — curly braces, each statement on its own line
if (index === -1) {
    break;
}

if (! element) {
    return 0;
}

if (query === '') {
    return;
}
```

## Eye-verify frontend changes (browser/runtime)

A change that renders UI calls for **seeing it run in a real browser** — type-check and linting
can't see runtime/visual bugs: stale state, dead toggles, broken scroll / sticky / fixed
behaviour, z-index show-through, async races, untranslated-key leaks.

- **When:** the diff touches code that renders to users — JS/TS that drives the DOM, or a
  server-rendered template/component.
- **How:** drive it in a real browser. Use the project's browser eye-verify harness if it
  ships one (commonly under `tools/verify/`, with a setup doc loaded on demand); otherwise the
  `frontend-quality` skill's shipped harness (`scripts/`) or a Playwright MCP server.
  DOM/console first; screenshots back up visual claims.
- **Cover every testable, name the gaps.** Derive the checklist first (ticket steps, edge
  cases, design annotations), assert one testable per check, drive full flows and mutations
  (create → round-trip → delete) — not just the happy path — and list anything you couldn't
  drive as NOT-VERIFIED. A green run that quietly skipped cases is the failure mode to avoid.
- **Verify behaviour, not just geometry** — a fixed/sticky element must also not be painted
  over, and pop-out content (dropdowns / tooltips / modals) must still escape.
- **Drive the failure path.** Most "works locally" bugs live where an endpoint fails — force
  it to fail, assert the UI shows a visible error and a way forward (not a silent hang), then
  clear the fault and assert recovery.
- **In an ephemeral clone or git worktree**, the app may be served at a different host/port
  than the canonical checkout, so the harness can silently verify the *wrong* tree — confirm
  it targets *this* checkout, and sanity-check the host serves a real page before trusting a
  green. A hard 404 on the expected page is the signature of hitting the wrong host.
- If a harness genuinely can't run this session (no seeded data, wrong host served, no login),
  say so — record it as an explicit deferral rather than substituting reasoning for the browser
  or reporting an unqualified green.

The coverage contract, the traps that fake a green run, and fault injection are detailed in the
`frontend-quality` skill's `references/eye-verify.md`.

### Verify against the design, per element

When the change has an approved design (a mockup, a Figma frame, a ticket attachment), don't
eyeball the whole image and call it close — *"looks about right"* is how visual regressions
ship (a 4px-vs-8px radius, a lost gradient, a control 3px off-centre). Verify it **element by
element, attribute by attribute**, and record each delta as a fix or a question for the
designer. The full attribute rubric and the per-element scoring table live in the
`frontend-quality` skill's `references/design-verification.md` — that skill walks it as a
suggested step, and the `pull-requests` skill flags it before a PR.

---

## Fixing PHPStan Errors

When fixing a PHPStan error, first decide whether it represents a runtime bug a test could catch — and if so, write that test before the fix.

### Process

1. **Assess testability** — does the error represent a runtime bug a test could reproduce (a wrong argument type, a missing method, an incorrect return type used downstream)?
2. **Write the test first** — if a test can catch it, write a failing test that reproduces the error before applying the fix.
3. **Fix the code** — apply the fix so both the PHPStan error and the new test pass.
4. **Verify both** — confirm PHPStan reports no error and the test passes.

### When to Write a Test

Write a test when the PHPStan error indicates a fault that would surface at runtime:

- A method call on a value of the wrong type
- Missing or incorrect arguments to a function or method
- A return-type mismatch that would break callers
- Accessing a property or method that does not exist
- Any type error that would manifest as a runtime exception

### When to Skip the Test

Skip the test when the error is purely static and cannot cause a runtime failure:

- Missing return-type declarations
- PHPDoc mismatches with no runtime impact
- Unused variables or imports
- Generic-type parameter issues

---

## Signed Commits

Applies **only when the repository has commit signing enabled** (e.g. `git config commit.gpgsign` is `true`, or a `user.signingkey` / `gpg.format` is set). If signing is not enabled, this guideline does not apply — commit normally.

### Never fall back to an unsigned commit

When signing is enabled, every commit must be signed. If the signing backend or agent (1Password, `gpg-agent`, `ssh-agent`, a hardware key, etc.) is unavailable, locked, or not responding:

- **Stop and surface the failure** to the user with the exact error.
- **Do not** retry with `--no-gpg-sign`, unset `commit.gpgsign`, or otherwise produce an unsigned commit to "get past" the problem.

A missing signature is a blocker to resolve (unlock the agent, re-authenticate 1Password, plug in the key), not a step to skip. Let the user fix the signing setup, then commit signed.

---

## Verification Before Completion

Before claiming any work is complete or successful, run the verification command fresh and confirm the output. Evidence before claims, always.

### Claims About How the Code Behaves — Trace, Don't Assume

A claim about **how the code currently behaves** — a root cause, an existing mechanism, or present behavior — in a spec, PR, commit message, code-review finding, issue, comment, or answer must be traced to the actual code (or observed at runtime) **before** you write it, never asserted from plausibility. (This governs statements of *fact about the present code*; the *intended* future behavior a spec or PR proposes is fine when it's clearly framed as a requirement, proposal, or decision — not disguised as a fact about what already exists.) Every illustrative example must be one you actually observed, never invented to fit a guess. A wrong "why" is worse than none: reproduction steps, tests, QA testables, and the fix itself all get built on the stated cause, so one unverified guess corrupts everything derived from it. When you have not traced it, say so — mark it `NEEDS-CONFIRMATION` or ask — rather than asserting. (A ticket once claimed a list was "sorted by display name" and backed it with an example that could not occur; the sort actually keyed on an internal identifier — one grep away. The trace is cheap; the false premise is not.)

### Required Before Any Completion Claim

1. **Run** the relevant command (in the current message, not from memory)
2. **Read** the full output
3. **Confirm** it supports the claim
4. **Then** state the result with evidence

| Claim            | Required verification                                            |
|------------------|------------------------------------------------------------------|
| Tests pass       | The project's test command, output showing 0 failures            |
| Code style clean | The project's formatter/style checker, output showing no changes |
| Linting clean    | The project's linter, output showing 0 errors                    |
| Types check      | The project's type checker, output showing 0 errors              |
| Bug fixed        | The previously failing test now passes                           |
| Feature complete | All related tests pass                                           |

Use the project's own commands — check its `composer.json` / `package.json` scripts, CI config, or sibling docs to find them. Do not assume a specific tool.

### Delegating the checks

Where the project has dedicated quality-check skills synced, delegate to them — `backend-quality` for backend files, `frontend-quality` for frontend files, both when a change spans both. Otherwise, run the project's own equivalent commands directly.

### Never Use Without Evidence

- "should work now"
- "that should fix it"
- "looks correct"
- "I'm confident this works"

These phrases indicate missing verification. Run the command first, then report what actually happened.

---

# Laravel Package Guidelines

These guidelines supplement the framework-agnostic Package Boost
Guidelines (`foundation.md`) for Composer packages that target
Laravel. A consumer receives both files, composed — read this one
together with `foundation.md`, not instead of it.

Apply this file only when `composer.json` declares a Laravel
dependency — a `require.illuminate/*` entry or
`require.laravel/framework`. A framework-agnostic package ignores
everything below.

## Laravel Context

A Laravel package has no host application of its own. A Laravel
kernel is booted only at test time, by Orchestra Testbench. The base
test case is `Orchestra\Testbench\TestCase`.

- `composer.json`'s `require.illuminate/*` (or
  `require.laravel/framework`) defines the supported Laravel range.
  Check it before using a version-specific framework API.
- The service provider is the package's entry point into a host
  app. One per package, named `{PackageStudly}ServiceProvider`,
  registered under `extra.laravel.providers` for package discovery.
- Test fixtures — migrations, routes, views, factories — live under
  `workbench/`, not `tests/`. Testbench's conventions place them
  there; follow them.

## Use `vendor/bin/testbench`, not `php artisan`

Running artisan directly against the package fails — there is no
host application. Use Testbench's binary, which boots a kernel
first:

| Instead of | Use |
|---|---|
| `php artisan test` | `vendor/bin/pest` or `vendor/bin/phpunit` |
| `php artisan tinker` | `vendor/bin/testbench tinker` |
| `php artisan make:*` | Create files manually under `src/` |
| `php artisan vendor:publish` | `vendor/bin/testbench vendor:publish` |

Register the package's service provider in `testbench.yaml` under
`providers:` so Testbench boots it. Published files land in
`workbench/` by default, not the `config/` or `resources/` of a
host app.

### Commands that require `laravel/boost`

These apply only when the package has `laravel/boost` as a dev
dependency. Skip them if Boost is not installed — `boost sync`
prints a warning and moves on.

| Instead of | Use |
|---|---|
| `php artisan boost:install` | `vendor/bin/testbench boost:install` |
| `php artisan boost:mcp` | `vendor/bin/testbench boost:mcp` |

## Cross-Version Compatibility

Supporting multiple Laravel and PHP majors in one release is routine
for a Laravel package. Constraints use `||` between major ranges
(`^12.0||^13.0`), and CI runs a matrix that includes `prefer-lowest`
so the declared floor is actually exercised.

- Activate the `cross-version-laravel-support` skill **before**
  writing version-spanning code.
- Activate the `ci-matrix-troubleshooting` skill **after** a matrix
  cell has failed.
- See the `package-development` skill for the Testbench and
  `workbench/` layout.

---

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

## Extending boost-core

If your package authors a custom `FileEmitter` (to write a file like
`.mcp.json` into the host during `boost sync`), declare the
`boost-extension` tag in your `boost.php` `withTags([...])`. That pulls
the `writing-file-emitter` skill — gated off by default so consumers
who do not extend the engine don't carry it, which is why an
emitter-authoring package has to opt in explicitly. The same tag pulls
`skill-authoring` for writing boost-family skills.

## Documentation Files

Only create or edit documentation (README, CHANGELOG, docs/) when
explicitly requested or when a behaviour change requires it.

## Replies

Be concise. Focus on what changed and why. Skip restating what the
diff already shows.
