<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Reusable key-name redaction + value-truncation walker. Extracted from
 * `KeyRedactingSanitizer` so the payload sanitizer and the failure-context
 * collector share one redaction vocabulary (`capture.redact_keys`) and one
 * truncation rule — secrets are redacted by key name identically wherever a
 * value might reach a human-facing surface (modal, markdown export).
 */
final readonly class KeyRedacter
{
    /**
     * @param  list<string>  $redactKeys  regex patterns (without delimiters), matched case-insensitively + anchored (`^…$`)
     */
    public function __construct(
        private array $redactKeys,
        private int $maxFieldBytes = 2048,
    ) {}

    /**
     * Recursively redact any string-keyed entry whose key matches a redact
     * pattern, and truncate over-long string values (leaving PHP-serialized
     * blobs intact — truncating them yields unparseable data).
     */
    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $inner) {
                if (is_string($key) && $this->keyShouldRedact($key)) {
                    $out[$key] = '[REDACTED]';

                    continue;
                }

                $out[$key] = $this->redact($inner);
            }

            return $out;
        }

        if (is_string($value) && strlen($value) > $this->maxFieldBytes) {
            if ($this->looksSerialized($value)) {
                return $value;
            }

            return substr($value, 0, $this->maxFieldBytes) . '…[truncated]';
        }

        return $value;
    }

    private function looksSerialized(string $value): bool
    {
        return str_starts_with($value, 'O:') || str_starts_with($value, 'C:') || str_starts_with($value, 'a:');
    }

    private function keyShouldRedact(string $key): bool
    {
        foreach ($this->redactKeys as $pattern) {
            if (@preg_match('/^' . $pattern . '$/i', $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
