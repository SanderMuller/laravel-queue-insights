# Security Policy

## Reporting a vulnerability

Open a private advisory on GitHub (`Security` → `Report a vulnerability`) or email `sander@hihaho.com`. Please do not file public issues for security bugs.

## Supported versions

Only the latest minor release receives security fixes. Pin to a version you can keep updated.

## Payload capture is opt-in by design

`queue-insights` persists **metadata only** by default. `QUEUE_INSIGHTS_CAPTURE_PAYLOADS` must be explicitly set to `metadata` or `full` to store more.

Laravel queue payloads embed serialized (and often encrypted) job state inside `data.command`. The default regex-based `KeyRedactingSanitizer` cannot see inside PHP-serialized blobs — secrets like `s:8:"password";s:7:"hunter2";` survive redaction and would land in Redis as plaintext.

**If you enable `full`, you MUST bind a custom `PayloadSanitizer`** that understands your job shape — unserialize/decrypt `data.command`, scrub sensitive fields on the inflated object graph, then return only safe projections. See the README for modes and binding.

## Authorization

The bundled dashboard route is gated by a `viewQueueInsights` Gate that the **host app must define**. Without it the package denies access, but unreviewed host-app policies would expose job class names, counts, and (if capture is enabled) payload contents.
