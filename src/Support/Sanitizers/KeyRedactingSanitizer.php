<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support\Sanitizers;

use Illuminate\Queue\Events\JobProcessed;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use SanderMuller\QueueInsights\Support\KeyRedacter;
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

        $redacted = (new KeyRedacter($this->redactKeys, $this->maxFieldBytes))->redact($payload);

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
}
