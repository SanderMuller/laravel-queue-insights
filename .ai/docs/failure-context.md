# Failure context — internals + touchpoints

AI-facing reference for the failure-context subsystem: the sanitized `Context`
facade snapshot + environment snapshot captured when a job or scheduled task
fails. End-user docs live in `README.md`. Read this before changing the listed
files.

## What it captures

On failure (job or scheduled task), gated on `failure_context.enabled`:

- **`app_context`** — the visible Laravel `Context` facade (`Context::all()`),
  optionally restricted to `failure_context.context_keys`, then **redacted by
  key name** through the same `capture.redact_keys` vocabulary as payloads and
  capped at `failure_context.max_value_bytes`. Hidden context is **never**
  captured (it holds package internals like `qi_origin`).
- **`environment`** — `host` (`gethostname`), `pid` (`getmypid`), `env`
  (`app()->environment()`), `release` (the `release_resolver`: callable →
  invoked; string → `config()` key; null → `getenv('APP_VERSION')`).

## Touchpoints

| File | Role |
|---|---|
| `src/Support/FailureContextCollector.php` | Gather + sanitize. Stateless (Octane-safe, no binding). `collect()` returns `['app_context' => [...], 'environment' => [...]]`. |
| `src/Support/KeyRedacter.php` | Shared key-regex redaction + value truncation. Extracted from `KeyRedactingSanitizer` (which now delegates to it) so payload + context redact identically. |
| `src/Support/FailureContextStore.php` | Redis hash `qi:failure-ctx:{uuid}` (fields `app_context`/`environment` JSON, `failure_context.ttl_seconds` TTL). `read()`/`write()`. Mirrors `InitiatorStore`. |
| `src/Listeners/RecordJobFailed.php` | `captureFailureContext()` — collects, stores by uuid, returns the snapshot for the alert event. |
| `src/Listeners/RecordScheduledTaskFailed.php` | `deepestPrevious()` adds `inner_class`/`inner_message` to the exception payload; collects + `RunStore::stampFailureContext` on both the stampException (Finished-already) and recordFinish (synthesize) paths. |
| `src/Scheduler/RunStore.php` | `stampFailureContext()` HSETs `app_context`/`environment` onto the run hash (inherits the run's TTL). |
| `src/Scheduler/ScheduleReader.php` | `runDetail()` decodes `app_context`/`environment`. |
| `src/Dashboard/DashboardData.php` | Hydrates `$selectedFailed['failure_context']` via `FailureContextStore::read` (one HGETALL on the selected failed row). |
| `resources/views/partials/failure-context-section.blade.php` | Shared Context + Environment visual section (both modals). |
| `resources/views/components/failed-modal.blade.php` / `schedule-run-modal.blade.php` | Include the partial + append `## Context` / `## Environment` to the markdown export. |
| `src/Events/JobFailedAlert.php` (`context`) / `Events/ScheduledTaskFailed.php` (`failureContext`) | Carry the snapshot to host listeners. Both trailing + defaulted. |
| `config/queue-insights.php` `failure_context.*` | Config block. |
| `src/Support/FailureContextConfigValidator.php` | Validation (split out of `ConfigValidator` for the complexity cap, like `AlertsConfigValidator`). |

## What NOT to do

- **Don't capture hidden context.** `Context::allHidden()` holds `qi_origin` and
  other internals — visible only.
- **Don't bypass the redactor.** Every value reaching the modal/markdown export
  must pass through `KeyRedacter` (the export is pasted into AI/trackers).
- **Don't store unbounded values.** The `max_value_bytes` cap is the discipline;
  the markdown export and Redis hash both rely on it.
- **Don't add ctor params to the shipped events anywhere but the trailing,
  defaulted position** — hosts construct `ScheduledTaskFailed` in tests.
- **Don't use the `env()` helper for the release** — it returns null under
  `config:cache`. Use `getenv()` (real process env) or a config key.
- **Don't capture peak memory or split stdout/stderr** — evaluated and rejected
  (see the spec's Non-goals: process-wide peak misleads + OOM bypasses the
  listener; Laravel merges scheduler output to one file).
