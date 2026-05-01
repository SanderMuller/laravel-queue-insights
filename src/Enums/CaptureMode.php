<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Enums;

/**
 * Resolved value of `queue-insights.capture.payloads`.
 *
 * Backed by the historical string config so a host writing
 * `'capture' => ['payloads' => 'metadata']` keeps working — the package
 * reads that string into the enum via `tryFrom`. Every internal call
 * site uses the enum from there on so the three known modes are
 * exhaustive (PHPStan flags a missing match arm if a fourth case is
 * added).
 */
enum CaptureMode: string
{
    /** No payload fields persisted on the completed-stream entry. */
    case Off = 'off';

    /** displayName / maxTries / timeout / backoff metadata only. */
    case Metadata = 'metadata';

    /** Full sanitized command body + metadata. SECURITY: see SECURITY.md. */
    case Full = 'full';

    /**
     * True when the listener should write any payload_* fields onto the
     * stream entry. `Off` short-circuits the sanitizer pipeline.
     */
    public function writesPayloadFields(): bool
    {
        return $this !== self::Off;
    }
}
