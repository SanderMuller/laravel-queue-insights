<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Str;

/**
 * Translate between the two names one SQS-backed queue answers to.
 *
 * A queue connection may carry a `suffix` (`SQS_SUFFIX` on Vapor; a
 * per-environment suffix Laravel Cloud sets on its managed connection). Laravel
 * appends it when it builds the queue URL — see
 * `Illuminate\Queue\SqsQueue::suffixQueue()` — so ONE queue has two names:
 *
 *   logical  `default`                  what you dispatch to, what config lists
 *   physical `default-{suffix}`         what AWS knows, what the URL ends with
 *
 * The package keys everything on the **logical** name. It is the one an
 * operator recognises, the one `snapshots[]` is written with, and the one
 * Laravel Cloud's own UI shows (`Illuminate\Foundation\Cloud\Queue::
 * normalizeQueue()` strips prefix and suffix for exactly this reason).
 *
 * Why this matters beyond cosmetics: the producer side (`JobQueued`) only ever
 * sees the logical name, while the worker side reads the queue off the job,
 * where `SqsJob::getQueue()` returns the full URL — physical. Keyed as-is the
 * two never meet: the worker's `zrem` misses the producer's `pending-zset`
 * entry, every job leaves a phantom pending row until it ages out, and the
 * dashboard renders one queue as two rows. Collapsing to logical at the point
 * of canonicalisation is what keeps them reconciled.
 *
 * @internal
 */
final class SqsQueueName
{
    /**
     * The connection's queue-name suffix.
     *
     * Callers hand over the *canonical* connection name, which
     * `connection_aliases` may have renamed away from any
     * `queue.connections.*` key. When the direct lookup misses, the alias map
     * is walked backwards to the source connections that resolve to this name
     * — without that, an aliased suffixed connection would silently resolve no
     * suffix and re-split producer and worker keys.
     *
     * Sources are consulted in declaration order and the first suffix found
     * wins; two aliased connections disagreeing on a suffix is already an
     * incoherent config, not a case to arbitrate here.
     */
    public static function suffixFor(string $connection): string
    {
        if ($connection === '') {
            return '';
        }

        $direct = self::suffixFromConfig($connection);

        if ($direct !== '') {
            return $direct;
        }

        foreach (Config::array('connection_aliases') as $source => $target) {
            if (! is_string($source) || $source === $connection || $target !== $connection) {
                continue;
            }

            $suffix = self::suffixFromConfig($source);

            if ($suffix !== '') {
                return $suffix;
            }
        }

        return '';
    }

    /**
     * Both shapes a suffix appears in: a plain SQS connection carries it at
     * the top level, Laravel Cloud's `cloud` wrapper nests the real connection
     * under `connection`.
     */
    private static function suffixFromConfig(string $connection): string
    {
        $nested = config("queue.connections.{$connection}.connection.suffix");
        $suffix = is_string($nested) && $nested !== ''
            ? $nested
            : config("queue.connections.{$connection}.suffix");

        return is_string($suffix) ? $suffix : '';
    }

    /**
     * Logical name → physical name, mirroring `SqsQueue::suffixQueue()`
     * including its FIFO rule: the suffix goes *before* the `.fifo` marker,
     * which AWS requires to stay last.
     */
    public static function physical(string $queue, string $suffix): string
    {
        // Already a queue URL: the physical name is baked in — suffixing it
        // would corrupt the URL.
        if ($suffix === '' || preg_match('/^https?:\/\//i', $queue) === 1) {
            return $queue;
        }

        if (str_ends_with($queue, '.fifo')) {
            return Str::finish(Str::beforeLast($queue, '.fifo'), $suffix) . '.fifo';
        }

        return Str::finish($queue, $suffix);
    }

    /**
     * Physical name → logical name. The inverse of {@see physical()}, and a
     * no-op for a name that never carried the suffix — so it is safe to apply
     * to a value of unknown provenance (a config entry, a hand-typed queue,
     * an already-canonical stored key).
     *
     * Expects a bare name, not a URL: callers strip the URL down to its last
     * segment first ({@see CanonicalQueueKey}).
     */
    public static function logical(string $queue, string $suffix): string
    {
        if ($suffix === '') {
            return $queue;
        }

        if (str_ends_with($queue, '.fifo')) {
            $bare = Str::beforeLast($queue, '.fifo');

            return str_ends_with($bare, $suffix)
                ? Str::beforeLast($bare, $suffix) . '.fifo'
                : $queue;
        }

        return str_ends_with($queue, $suffix) ? Str::beforeLast($queue, $suffix) : $queue;
    }
}
