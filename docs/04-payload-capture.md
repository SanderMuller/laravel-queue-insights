# Payload capture

Off by default. Laravel payloads embed serialized and sometimes encrypted job state, and a regex over JSON keys can't sanitize that safely.

Three modes via `QUEUE_INSIGHTS_CAPTURE_PAYLOADS`:

| Mode              | Behavior                                                                                                                                 |
|-------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| `off` *(default)* | No payload persisted.                                                                                                                    |
| `metadata`        | `displayName`, `maxTries`, `timeout`, `backoff` only. No user data, no serialized command body.                                          |
| `full`            | Raw body after a sanitizer pass. Apps with sensitive jobs MUST bind a custom `PayloadSanitizer` that understands their job shape.        |

Read [`SECURITY.md`](https://github.com/SanderMuller/laravel-queue-insights/blob/main/SECURITY.md) before enabling `full`.

## Pending payload capture (separate budget)

The completed-stream `capture.payloads` setting controls what's persisted on **completed and failed** rows. Pending and in-flight rows have their own knob because the memory math differs structurally, completed-stream entries are MAXLEN-trimmed (`N × bytes`), but pending hashes fan out as `max_per_queue × queues × TTL`, which on a 10k-row × 10-queue host is ~400 MB at 4 KB/row.

```bash
# .env
QUEUE_INSIGHTS_PENDING_CAPTURE_PAYLOADS=metadata    # off | metadata | full
QUEUE_INSIGHTS_PENDING_INCLUDE_COMMAND_BODY=false   # opt in to persist data.command bytes
```

Defaults: `off` (no payload fields written), `4096` byte cap per pending hash (a quarter of the completed-stream cap), and `data.command` omitted even under `full` until the host explicitly opts in. The same `capture.redact_keys` regex list is applied either way.

## Custom payload sanitizer

The default `KeyRedactingSanitizer` can't see inside PHP-serialized `data.command` bodies. Apps with sensitive jobs should bind their own:

```php
// app/Providers/AppServiceProvider.php
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;

$this->app->bind(PayloadSanitizer::class, YourSanitizer::class);
```
