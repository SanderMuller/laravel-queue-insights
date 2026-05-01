<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support\Sanitizers;

use Illuminate\Queue\Events\JobProcessed;
use SanderMuller\QueueInsights\Contracts\PayloadSanitizer;
use Throwable;

final class MetadataOnlySanitizer implements PayloadSanitizer
{
    public function sanitize(JobProcessed $event): array
    {
        try {
            $payload = $event->job->payload();
        } catch (Throwable) {
            return ['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted'];
        }

        $data = $payload['data'] ?? [];
        $commandName = is_array($data) ? ($data['commandName'] ?? '') : '';
        if (is_string($commandName) && str_contains($commandName, 'CallQueuedClosure')) {
            return ['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted'];
        }

        $fields = [];

        foreach (['displayName', 'maxTries', 'timeout', 'backoff'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_scalar($value) || $value === null || is_array($value)) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
