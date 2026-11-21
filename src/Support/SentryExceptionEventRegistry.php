<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Closure;
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

    /** @var WeakMap<Options, true>|null */
    private static ?WeakMap $hooked = null;

    /** @var WeakMap<Throwable, Closure(string): void>|null */
    private static ?WeakMap $pending = null;

    public static function record(Throwable $exception, string $eventId): void
    {
        self::map()[$exception] = $eventId;

        $callback = self::$pending[$exception] ?? null;
        if ($callback === null) {
            return;
        }

        unset(self::$pending[$exception]);

        try {
            $callback($eventId);
        } catch (Throwable) {
            // Runs inside Sentry's beforeSend: never break the host's capture.
        }
    }

    /**
     * Run `$callback` with the event ID once Sentry captures `$exception`.
     * Laravel's `schedule:run` dispatches `ScheduledTaskFailed` before it
     * reports the exception, so the failure listener records the run first
     * and links the event here. Dropped with the exception if it is never
     * captured.
     *
     * @param  Closure(string): void  $callback
     */
    public static function whenRecorded(Throwable $exception, Closure $callback): void
    {
        self::$pending ??= new WeakMap();
        self::$pending[$exception] = $callback;
    }

    public static function get(Throwable $exception): ?string
    {
        return self::map()[$exception] ?? null;
    }

    /**
     * No-op when no Sentry client is bound, and when this client's options
     * already carry the hook. Must run after the host's Sentry provider has
     * set its hub, so it runs on the first `ScheduledTaskStarting`, not at boot.
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

        self::$hooked ??= new WeakMap();
        if (isset(self::$hooked[$options])) {
            return;
        }

        self::$hooked[$options] = true;

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
