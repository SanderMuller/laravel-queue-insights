<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Producer-side payload sanitizer for the pending/in-flight hash.
 *
 * The completed-stream listener relies on the bound `PayloadSanitizer`
 * contract, which takes a `JobProcessed` event with a fully wrapped `Job`
 * instance. At `JobQueued` time the worker hasn't picked the job up yet,
 * so we only have the raw payload string `$event->payload` and the
 * Laravel-decoded array shape — no `Job` wrapper to hand to the existing
 * sanitizer.
 *
 * Rather than break the public `PayloadSanitizer` contract, this helper
 * mirrors `KeyRedactingSanitizer`'s array-mode logic against the decoded
 * queued payload directly. Identical redaction rules + identical
 * looks-serialized guard + same closure/encrypted skip — the difference is
 * input source (decoded array vs `Job::payload()`) and the byte-cap
 * parameter (pending-specific, see `pending.capture.max_payload_bytes`).
 *
 * Returns the same field-name surface so the pending-modal and the
 * completed-modal share the `structured-payload` rendering path
 * (`payload_body` is the canonical key, with `payload_displayName` /
 * `payload_maxTries` / `payload_timeout` / `payload_backoff` for the
 * metadata mode).
 *
 * Listener helper. Not part of the package's semver-stable public API,
 * but kept un-`@internal` so the unit-test suite (separate root namespace)
 * can exercise it directly.
 */
final class PendingPayloadSnapshot
{
    /**
     * Build the `payload_*` field map to merge into the `pending:{uuid}`
     * hash. Returns `[]` when capture is off, the payload is unparseable,
     * or the encoded body exceeds the cap.
     *
     * @param  array<array-key, mixed>|null  $payload            Decoded `JobQueued::payload` JSON.
     * @param  'off'|'metadata'|'full'       $mode               Resolved `pending.capture.payloads`.
     * @param  list<non-empty-string>        $redactKeys         Regex patterns shared with completed.
     * @param  bool                          $includeCommandBody When false (default), the serialized
     *                                                            `data.command` PHP blob is replaced
     *                                                            with a `[OMITTED_BY_PENDING_CAPTURE]`
     *                                                            sentinel before encoding. `redact_keys`
     *                                                            cannot traverse PHP-serialized bytes,
     *                                                            so persisting them verbatim into a
     *                                                            per-uuid Redis hash (TTL × N queues
     *                                                            × max_per_queue rows) widens the
     *                                                            confidentiality window vs the
     *                                                            MAXLEN-bounded completed stream.
     *                                                            Operators flip true once their job
     *                                                            classes are confirmed not to carry
     *                                                            secrets as properties.
     * @return array<string, scalar|null>
     */
    public static function build(?array $payload, string $mode, array $redactKeys, int $maxFieldBytes, int $maxPayloadBytes, bool $includeCommandBody = false): array
    {
        if ($mode === 'off' || ! is_array($payload)) {
            return [];
        }

        if (self::isClosureOrEncrypted($payload)) {
            return ['payload_note' => 'payload_not_persisted', 'payload_reason' => 'closure_or_encrypted'];
        }

        $fields = [];

        foreach (['displayName', 'maxTries', 'timeout', 'backoff'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_scalar($value) || $value === null) {
                $fields['payload_' . $key] = $value;

                continue;
            }
            if (is_array($value)) {
                // `backoff` can be an int list (`[1, 5, 10]`) — keep it
                // round-trippable for the modal renderer that already
                // knows how to format both shapes.
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $fields['payload_' . $key] = $encoded === false ? null : $encoded;
            }
        }

        if ($mode === 'metadata') {
            return $fields;
        }

        $forSanitize = $payload;
        if (! $includeCommandBody && isset($forSanitize['data']) && is_array($forSanitize['data']) && isset($forSanitize['data']['command'])) {
            // Replace the serialized command blob with a sentinel BEFORE
            // walking — the redact-keys regex cannot recurse into PHP-
            // serialized bytes, so any sensitive property would otherwise
            // round-trip into Redis verbatim. The pending-modal renders
            // the sentinel as a "Job instance omitted by pending capture"
            // note. Operators who need the instance-properties view flip
            // `pending.capture.include_command_body=true` after confirming
            // their job classes don't carry secrets as object properties.
            $forSanitize['data']['command'] = '[OMITTED_BY_PENDING_CAPTURE]';
        }

        $redacted = self::walk($forSanitize, $redactKeys, $maxFieldBytes);
        $encoded = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $fields['payload_error'] = 'payload_encoding_failed';

            return $fields;
        }

        if (strlen($encoded) > $maxPayloadBytes) {
            $fields['payload_error'] = 'payload_too_large';
            $fields['payload_size'] = strlen($encoded);

            return $fields;
        }

        $fields['payload_body'] = $encoded;

        return $fields;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function isClosureOrEncrypted(array $payload): bool
    {
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            return false;
        }

        $commandName = $data['commandName'] ?? '';
        if (is_string($commandName) && str_contains($commandName, 'CallQueuedClosure')) {
            return true;
        }

        $command = $data['command'] ?? null;

        return is_string($command) && $command !== '' && ! str_starts_with($command, 'O:') && ! str_starts_with($command, 'C:');
    }

    /**
     * @param  list<string>  $redactKeys
     */
    private static function walk(mixed $value, array $redactKeys, int $maxFieldBytes): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $inner) {
                if (is_string($key) && self::keyShouldRedact($key, $redactKeys)) {
                    $out[$key] = '[REDACTED]';

                    continue;
                }
                $out[$key] = self::walk($inner, $redactKeys, $maxFieldBytes);
            }

            return $out;
        }

        if (is_string($value) && strlen($value) > $maxFieldBytes) {
            // Same invariant as KeyRedactingSanitizer: serialized blobs
            // stay intact — truncating them would corrupt the bytes the
            // SerializedCommandReader pipeline relies on for instance
            // properties + ValueParser nested-context extraction. The
            // outer payload-bytes cap still bounds total growth.
            if (self::looksSerialized($value)) {
                return $value;
            }

            return substr($value, 0, $maxFieldBytes) . '…[truncated]';
        }

        return $value;
    }

    private static function looksSerialized(string $value): bool
    {
        return str_starts_with($value, 'O:') || str_starts_with($value, 'C:') || str_starts_with($value, 'a:');
    }

    /**
     * @param  list<string>  $redactKeys
     */
    private static function keyShouldRedact(string $key, array $redactKeys): bool
    {
        foreach ($redactKeys as $pattern) {
            if (@preg_match('/^' . $pattern . '$/i', $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
