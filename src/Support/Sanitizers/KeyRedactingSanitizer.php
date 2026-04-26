<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support\Sanitizers;

use Illuminate\Queue\Events\JobProcessed;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use Throwable;

final readonly class KeyRedactingSanitizer implements PayloadSanitizer
{
    /**
     * @param  list<string>  $redactKeys  Regex patterns (without delimiters)
     */
    public function __construct(
        private array $redactKeys,
        private int $maxFieldBytes = 2048,
        private int $maxPayloadBytes = 16384,
    ) {}

    public function sanitize(JobProcessed $event): array
    {
        try {
            $payload = $event->job->payload();
        } catch (Throwable) {
            return ['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted'];
        }

        if ($this->isClosureOrEncrypted($payload)) {
            return ['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted'];
        }

        $redacted = $this->walk($payload);

        $encoded = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['error' => 'payload_encoding_failed'];
        }

        if (strlen($encoded) > $this->maxPayloadBytes) {
            return ['error' => 'payload_too_large', 'size' => strlen($encoded)];
        }

        return ['body' => $encoded];
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function isClosureOrEncrypted(array $payload): bool
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

    private function walk(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $inner) {
                if (is_string($key) && $this->keyShouldRedact($key)) {
                    $out[$key] = '[REDACTED]';

                    continue;
                }

                $out[$key] = $this->walk($inner);
            }

            return $out;
        }

        if (is_string($value) && strlen($value) > $this->maxFieldBytes) {
            // Keep PHP-serialized object/array blobs intact — truncating them would
            // produce invalid serialized data that downstream tooling (the modal's
            // structured-payload extractor) can't unserialize. The outer
            // max_payload_bytes cap on the whole encoded body still bounds growth.
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
