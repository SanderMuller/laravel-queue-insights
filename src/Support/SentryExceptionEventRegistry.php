<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Options;
use Sentry\SentrySdk;
use Throwable;
use WeakMap;

/**
 * Maps Throwable instances to the Sentry event ID captured for each one.
 *
 * Populated by a `beforeSend` hook installed at boot via `installBeforeSendHook()`.
 * Keyed by object identity so concurrent tasks (each a distinct exception object)
 * never collide — unlike `Hub::getLastEventId()`, which is shared mutable state
 * overwritten by every capture on the hub.
 *
 * @internal
 */
final class SentryExceptionEventRegistry
{
    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $map = null;

    public static function record(Throwable $exception, string $eventId): void
    {
        self::map()[$exception] = $eventId;
    }

    public static function get(Throwable $exception): ?string
    {
        return self::map()[$exception] ?? null;
    }

    /**
     * No-op when no Sentry client is bound. Must be called after the hub is
     * initialised — service-provider boot and inside test helpers that swap the hub.
     */
    public static function installBeforeSendHook(): void
    {
        if (! SentryAvailability::available()) {
            return;
        }

        $options = SentrySdk::getCurrentHub()->getClient()?->getOptions();

        if (! $options instanceof Options) {
            return;
        }

        $existing = $options->getBeforeSendCallback();
        $options->setBeforeSendCallback(
            static function (Event $event, ?EventHint $hint) use ($existing): ?Event {
                $result = $existing($event, $hint);
                if ($result !== null && $hint?->exception instanceof Throwable) {
                    self::record($hint->exception, (string) $event->getId());
                }

                return $result;
            }
        );
    }

    /** @return WeakMap<Throwable, string> */
    private static function map(): WeakMap
    {
        return self::$map ??= new WeakMap();
    }
}
