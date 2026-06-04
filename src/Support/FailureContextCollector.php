<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Gathers the debug context captured when a job or scheduled task fails: a
 * sanitized snapshot of the Laravel `Context` facade (visible keys only) plus
 * a small environment snapshot (host, pid, app env, release).
 *
 * Stateless — reads live state each call, so it's Octane-safe without a scoped
 * binding. Domain-agnostic: the same collector serves the job listener and the
 * scheduler listener.
 */
final class FailureContextCollector
{
    /**
     * @return array{app_context: array<array-key, mixed>, environment: array<string, scalar|null>}
     */
    public function collect(): array
    {
        if (! Config::bool('failure_context.enabled', true)) {
            return ['app_context' => [], 'environment' => []];
        }

        return [
            'app_context' => $this->appContext(),
            'environment' => $this->environment(),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function appContext(): array
    {
        if (! Config::bool('failure_context.capture_app_context', true)) {
            return [];
        }

        $all = $this->safeVisibleContext();
        if ($all === []) {
            return [];
        }

        // Optional allowlist — restrict to exactly these keys when set.
        $allowlist = array_values(array_filter(Config::array('failure_context.context_keys'), is_string(...)));
        if ($allowlist !== []) {
            $all = array_intersect_key($all, array_fill_keys($allowlist, true));
        }

        // Normalize objects (DTOs, JsonSerializable, Eloquent models) to arrays
        // FIRST so the key-redactor can walk their nested fields. Without this
        // an object value is returned untouched by the array-only walker and
        // JSON-encoded verbatim into storage + the export — a sensitive property
        // inside it would bypass key-based redaction entirely.
        $normalized = $this->normalizeForRedaction($all);

        // Redact by key name through the SAME vocabulary as the payload
        // sanitizer, capped at the failure-context byte limit. The markdown
        // export is pasted into AI/trackers, so this is the PII gate.
        $redacter = new KeyRedacter(
            array_values(array_filter(Config::array('capture.redact_keys'), is_string(...))),
            Config::int('failure_context.max_value_bytes', 2048),
        );

        /** @var array<array-key, mixed> $redacted */
        $redacted = $redacter->redact($normalized);

        return $redacted;
    }

    /**
     * Round-trip through JSON so object / JsonSerializable values become plain
     * arrays the key-redactor can recurse into. Falls back to the raw array
     * when the structure isn't JSON-serializable.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalizeForRedaction(array $value): array
    {
        $encoded = json_encode($value);
        if ($encoded === false) {
            return $value;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function safeVisibleContext(): array
    {
        try {
            $all = Context::all();
        } catch (Throwable) {
            return [];
        }

        return is_array($all) ? $all : [];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function environment(): array
    {
        if (! Config::bool('failure_context.capture_environment', true)) {
            return [];
        }

        $host = gethostname();
        $pid = getmypid();

        return [
            'host' => is_string($host) && $host !== '' ? $host : null,
            'pid' => is_int($pid) ? $pid : null,
            'env' => $this->appEnv(),
            'release' => $this->resolveRelease(),
        ];
    }

    private function appEnv(): ?string
    {
        try {
            $env = app()->environment();
        } catch (Throwable) {
            return null;
        }

        return $env !== '' ? $env : null;
    }

    /**
     * Resolve a deploy/release identifier: a callable is invoked; a string is
     * looked up as a `config()` key; null falls back to `getenv('APP_VERSION')`
     * (the real process env, which — unlike the `env()` helper — survives
     * `config:cache`).
     */
    private function resolveRelease(): ?string
    {
        $resolver = config('queue-insights.failure_context.release_resolver');

        if (is_callable($resolver)) {
            try {
                $value = $resolver();
            } catch (Throwable) {
                return null;
            }

            return is_string($value) && $value !== '' ? $value : null;
        }

        if (is_string($resolver) && $resolver !== '') {
            $value = config($resolver);

            return is_string($value) && $value !== '' ? $value : null;
        }

        // `getenv` reads the real process environment, so it survives
        // `config:cache` (unlike the `env()` helper, which returns null once
        // the framework stops loading `.env`).
        $version = getenv('APP_VERSION');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
