<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Throwable;

/**
 * Reads PHP-serialized job-command payloads (the `data.command` field on a queue
 * payload) without instantiating the underlying class. Uses `allowed_classes => false`
 * so __wakeup, __destruct and constructors never run — safe to call on stored data
 * without re-triggering app side-effects.
 *
 * Property keys with the null-byte prefix encoding for protected (`\0*\0name`) and
 * private (`\0ClassName\0name`) members are cleaned to the bare name.
 */
final class SerializedCommandReader
{
    /**
     * @return array{class: string|null, properties: array<string, mixed>}|null
     *   `null` if the blob does not unserialize to an object.
     */
    public static function extract(string $blob): ?array
    {
        try {
            // `@` suppresses the E_WARNING that allowed_classes=false emits when it
            // demotes user-defined classes to __PHP_Incomplete_Class — we expect that.
            $decoded = @unserialize($blob, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        if (! is_object($decoded)) {
            return null;
        }

        $arr = (array) $decoded;
        $class = null;
        $properties = [];

        foreach ($arr as $key => $value) {
            $strKey = (string) $key;
            if ($strKey === '__PHP_Incomplete_Class_Name') {
                $class = is_string($value) ? $value : null;

                continue;
            }

            $properties[self::cleanKey($strKey)] = $value;
        }

        return ['class' => $class, 'properties' => $properties];
    }

    /**
     * Expand a nested `__PHP_Incomplete_Class` (the kind produced by
     * `unserialize(... ['allowed_classes' => false])`) into a clean property map —
     * same shape as the `properties` field returned by `extract()`. Use this from
     * the modal renderer to recursively reveal nested objects without ever
     * instantiating their classes.
     *
     * @return array<string, mixed>
     */
    public static function expandObject(object $obj): array
    {
        $arr = (array) $obj;
        $properties = [];

        foreach ($arr as $key => $value) {
            $strKey = (string) $key;
            if ($strKey === '__PHP_Incomplete_Class_Name') {
                continue;
            }

            $properties[self::cleanKey($strKey)] = $value;
        }

        return $properties;
    }

    /**
     * Best-effort class-name extraction from a nested `__PHP_Incomplete_Class` instance.
     */
    public static function classNameOf(object $obj): ?string
    {
        $arr = (array) $obj;
        $name = $arr['__PHP_Incomplete_Class_Name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * `\0*\0name` → `name` (protected); `\0ClassName\0name` → `name` (private).
     * Public properties have no null-byte prefix, returned unchanged.
     */
    private static function cleanKey(string $key): string
    {
        if (! str_contains($key, "\0")) {
            return $key;
        }

        $parts = explode("\0", $key);

        return $parts[count($parts) - 1];
    }

    /**
     * Inspect a serialized command body for Laravel job-chain context.
     *
     * Returns a structure when `chained` is non-empty (there is at least one
     * further job to point at). Returns `null` when the blob isn't an object,
     * has no `chained` property, the array is empty (last link in the chain),
     * or `data.command` isn't a plain serialized blob (e.g. encrypted base64).
     *
     * Each entry in `chained` is itself a serialized job body. Each one is
     * re-`extract()`-ed safely (allowed_classes=false, no constructors run)
     * to read the next job's class, connection, and queue — never trusting
     * the `O:NN:"<FQCN>":` prefix alone since a malformed entry could lie.
     *
     * Per-job `properties` carries the chained job's own constructor-bound
     * data, with framework-internal noise filtered out — same shape the
     * `serialized-properties` component renders for the parent job. Empty
     * map when the chained job has no inspectable user properties.
     *
     * @return array{
     *     next_class: string,
     *     remaining: int,
     *     chain_connection: ?string,
     *     chain_queue: ?string,
     *     jobs: list<array{class: string, connection: ?string, queue: ?string, properties: array<string, mixed>}>,
     * }|null
     */
    public static function extractChainContext(string $serialized): ?array
    {
        $extracted = self::extract($serialized);
        if ($extracted === null) {
            return null;
        }

        $chained = $extracted['properties']['chained'] ?? null;
        if (! is_array($chained) || $chained === []) {
            return null;
        }

        $outerConnection = self::nullableString($extracted['properties']['chainConnection'] ?? null);
        $outerQueue = self::nullableString($extracted['properties']['chainQueue'] ?? null);

        // Fail closed on ANY unparsable chained entry — silently skipping
        // would misorder the chain (a malformed `chained[0]` would let
        // `chained[1]` claim the "next" slot, even though Laravel tries
        // chained[0] first) AND would let `count($jobs)` diverge from
        // `count($chained)`, which the listener later round-trips lossily
        // through Redis. One bad entry = no chain context at all, both
        // surfaces consistent.
        $jobs = [];
        foreach ($chained as $entry) {
            if (! is_string($entry) || $entry === '') {
                return null;
            }

            $entryExtracted = self::extract($entry);
            if ($entryExtracted === null) {
                return null;
            }

            $entryClass = $entryExtracted['class'];
            if (! is_string($entryClass) || $entryClass === '') {
                return null;
            }

            // Per-link route override — Laravel's `dispatchNextJobInChain`
            // does `$next->onConnection($next->connection ?: $this->chainConnection)`
            // (Queueable.php:336). Prefer the link's own props, fall back to
            // the parent's chainConnection/chainQueue defaults.
            $entryProps = $entryExtracted['properties'];
            $jobs[] = [
                'class' => $entryClass,
                'connection' => self::nullableString($entryProps['connection'] ?? null) ?? $outerConnection,
                'queue' => self::nullableString($entryProps['queue'] ?? null) ?? $outerQueue,
                'properties' => self::filterFrameworkProps($entryProps),
            ];
        }

        $next = $jobs[0];

        return [
            'next_class' => $next['class'],
            'remaining' => count($jobs),
            'chain_connection' => $next['connection'],
            'chain_queue' => $next['queue'],
            'jobs' => $jobs,
        ];
    }

    /**
     * Strip Laravel queue/Bus framework internals from a serialized job's
     * properties map so the chain-detail view only surfaces user data —
     * not the routing/scheduling/middleware bookkeeping that's already
     * rendered as dedicated chips elsewhere in the modal.
     *
     * Mirrors the well-known field list in `structured-payload.blade.php`,
     * extended with chain-specific Queueable internals.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function filterFrameworkProps(array $properties): array
    {
        $framework = [
            'connection', 'queue', 'delay', 'afterCommit', 'middleware',
            'chainConnection', 'chainQueue', 'chained', 'chainCatchCallbacks',
            'job', 'jobId', 'attempts', 'maxTries', 'maxExceptions',
            'timeout', 'backoff', 'retryUntil', 'failOnTimeout',
            'batchId', 'shouldBeEncrypted', 'tags',
        ];

        return array_diff_key($properties, array_flip($framework));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Best-effort one-line summary for a value extracted from a serialized job.
     * Recurses one level into __PHP_Incomplete_Class instances ("ClassName {…}").
     */
    public static function summarize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_object($value)) {
            $arr = (array) $value;
            $class = $arr['__PHP_Incomplete_Class_Name'] ?? $value::class;

            return is_string($class) ? $class . ' {…}' : 'object {…}';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : 'array';
        }

        return gettype($value);
    }
}
