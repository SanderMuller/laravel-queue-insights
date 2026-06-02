<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;

/**
 * Shared validator for the `channels.{log,slack,mail}` block. The shape is
 * identical between `alerts.channels` (queue-side) and
 * `scheduler.alerts.channels` (scheduler-side); only the config-path prefix
 * differs. Both validator classes route through here so the rules cannot
 * drift.
 *
 * @internal
 */
final class ChannelsConfigValidator
{
    /**
     * Validate a `channels` block under the given root path. Pass null to
     * no-op (block omitted is allowed). `$rootPath` is the dotted config
     * path for error messages, e.g. `alerts.channels` or
     * `scheduler.alerts.channels`.
     */
    public static function validate(mixed $channels, string $rootPath): void
    {
        if ($channels === null) {
            return;
        }

        if (! is_array($channels)) {
            throw new QueueInsightsConfigException("queue-insights.{$rootPath} must be an array.");
        }

        self::validateLog($channels['log'] ?? null, "{$rootPath}.log");
        self::validateSlack($channels['slack'] ?? null, "{$rootPath}.slack");
        self::validateMail($channels['mail'] ?? null, "{$rootPath}.mail");
        self::validateSentry($channels['sentry'] ?? null, "{$rootPath}.sentry");
    }

    private static function validateLog(mixed $channel, string $path): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be an array.");
        }

        if (array_key_exists('enabled', $channel) && ! is_bool($channel['enabled'])) {
            throw new QueueInsightsConfigException("queue-insights.{$path}.enabled must be a boolean.");
        }

        if (isset($channel['level']) && ! is_string($channel['level'])) {
            throw new QueueInsightsConfigException("queue-insights.{$path}.level must be a string.");
        }
    }

    private static function validateSlack(mixed $channel, string $path): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be an array.");
        }

        if (array_key_exists('enabled', $channel) && ! is_bool($channel['enabled'])) {
            throw new QueueInsightsConfigException("queue-insights.{$path}.enabled must be a boolean.");
        }

        if (($channel['enabled'] ?? false) === true) {
            $url = $channel['webhook_url'] ?? null;
            if (! is_string($url) || $url === '') {
                throw new QueueInsightsConfigException(
                    "queue-insights.{$path}.webhook_url must be a non-empty string when slack is enabled."
                );
            }
        }

        if (isset($channel['channel']) && ! is_string($channel['channel'])) {
            throw new QueueInsightsConfigException(
                "queue-insights.{$path}.channel must be a string (e.g. \"#queue-alerts\") or omitted."
            );
        }
    }

    /**
     * The sentry channel carries no DSN/URL of its own — it captures into the
     * host's already-initialised Sentry hub — so only the enable toggle is
     * validated (no "required when enabled" branch like slack/mail).
     */
    private static function validateSentry(mixed $channel, string $path): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be an array.");
        }

        if (array_key_exists('enabled', $channel) && ! is_bool($channel['enabled'])) {
            throw new QueueInsightsConfigException("queue-insights.{$path}.enabled must be a boolean.");
        }
    }

    private static function validateMail(mixed $channel, string $path): void
    {
        if ($channel === null) {
            return;
        }

        if (! is_array($channel)) {
            throw new QueueInsightsConfigException("queue-insights.{$path} must be an array.");
        }

        if (array_key_exists('enabled', $channel) && ! is_bool($channel['enabled'])) {
            throw new QueueInsightsConfigException("queue-insights.{$path}.enabled must be a boolean.");
        }

        if (($channel['enabled'] ?? false) === true) {
            $to = $channel['to'] ?? null;
            if (! is_array($to) || $to === []) {
                throw new QueueInsightsConfigException(
                    "queue-insights.{$path}.to must be a non-empty array when mail is enabled."
                );
            }

            foreach ($to as $i => $address) {
                if (! is_string($address) || $address === '') {
                    throw new QueueInsightsConfigException(
                        "queue-insights.{$path}.to[{$i}] must be a non-empty string."
                    );
                }
            }
        }
    }
}
