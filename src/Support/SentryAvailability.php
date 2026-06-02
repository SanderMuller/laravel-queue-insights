<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Sentry\ClientInterface;
use Sentry\SentrySdk;

/**
 * Single source of truth for "can the sentry alert channel actually deliver?".
 *
 * It is NOT enough that the `sentry/sentry` package is autoloaded
 * (`function_exists`) — in Sentry 4.x the global capture functions resolve the
 * process hub via `SentrySdk::getCurrentHub()`, and `Hub::captureMessage()`
 * returns null (a silent no-op) when no client is bound. A host can carry
 * `sentry/sentry` as a transitive dependency without ever initialising a hub,
 * so the channel must require a bound client, not just the loaded functions.
 *
 * Used by `QueueAlertNotification::via()` (channel selection),
 * `SentryChannel::send()` (delivery guard), `Issue::channelConfigRoot()`
 * (so a scheduler-sentry-only block with no bound client falls back to the
 * queue-side channels instead of blackholing scheduler alerts), and
 * `AlertRulesPanelBuilder` (the dashboard's read-only diagnostic).
 *
 * @internal
 */
final class SentryAvailability
{
    public static function available(): bool
    {
        if (! function_exists('Sentry\captureMessage')) {
            return false;
        }

        // Reaching here means sentry/sentry's functions.php autoloaded, so the
        // SDK classes are present too — safe to touch the hub.
        return SentrySdk::getCurrentHub()->getClient() instanceof ClientInterface;
    }
}
