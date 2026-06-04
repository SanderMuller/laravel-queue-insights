<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Alerts\IssueDispatcher;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\ConnectionAlias;
use SanderMuller\QueueInsights\Support\FailureContextCollector;
use SanderMuller\QueueInsights\Support\FailureContextStore;
use SanderMuller\QueueInsights\Support\HourBucket;
use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Support\ResolveJobClass;
use Throwable;

final readonly class RecordJobFailed
{
    /**
     * See `RecordJobProcessed::MONOTONIC_TTL_SECONDS` — same 30 d
     * boundary, same dormant-class semantics.
     */
    private const int MONOTONIC_TTL_SECONDS = 2592000;

    /**
     * Per-failed-uuid runtime side-key TTL. 30 d matches the package's
     * other monotonic-side-key retention; failed_jobs rows are pruned
     * by the host's own schedule, so the side-key just needs to outlive
     * normal triage windows.
     */
    private const int FAILED_RUNTIME_TTL_SECONDS = 2592000;

    public function __construct(
        private ResolveJobClass $resolveJobClass,
        private IssueDispatcher $dispatcher,
    ) {}

    public function handle(JobFailed $event): void
    {
        try {
            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            // Canonicalise — see ConnectionAlias docblock.
            $connectionName = ConnectionAlias::canonical((string) $event->connectionName);
            $queueRaw = (string) $event->job->getQueue();
            $queueKey = CanonicalQueueKey::fromOrDefault($queueRaw, $connectionName);

            $class = $this->resolveJobClass->from($event->job, $connectionName, $queueKey);

            $now = CarbonImmutable::now('UTC');
            $nowTs = $now->getTimestamp();
            $bucket = $now->format('YmdH');
            $isoNow = $now->toIso8601String();

            $counterDays = max(1, Config::int('retention.failed_counters_days', 30));
            $this->incrFailedCounters($redis, $class, $connectionName, $bucket, HourBucket::startTs($bucket) + ($counterDays * 86400));
            $this->writeFailedMonotonic($redis, $class, $connectionName);

            $uuid = $event->job->uuid();
            if ($uuid !== null && $uuid !== '') {
                // Read `start:{uuid}` (microtime float written by RecordJobProcessing)
                // before DELing so we can compute runtime for the failure modal +
                // failed-row Runtime column. Mirrors RecordJobProcessed's path
                // for successful runs but writes to a uuid-keyed side-key — the
                // failed_jobs DB row has no duration field.
                $startKey = KeyPrefix::make("start:{$uuid}");
                $start = $redis->command('get', [$startKey]);
                $redis->command('del', [$startKey]);

                if (is_numeric($start)) {
                    $durationMs = max(0, (int) round((microtime(true) - (float) $start) * 1000));
                    $redis->command('setex', [
                        KeyPrefix::make("failed-runtime:{$uuid}"),
                        self::FAILED_RUNTIME_TTL_SECONDS,
                        (string) $durationMs,
                    ]);
                }

                // Belt-and-suspenders pending-tracking cleanup. RecordJobProcessing
                // already cleared on the pending → in-flight transition; this is
                // here for the rare case where that listener was missed.
                if (Config::bool('pending.enabled', true)) {
                    $redis->command('del', [KeyPrefix::make("pending:{$uuid}")]);
                    $redis->command('zrem', [KeyPrefix::make("pending-zset:{$connectionName}:{$queueKey}"), $uuid]);
                    // Inflight-zset entry was added by RecordJobProcessing.
                    $redis->command('zrem', [KeyPrefix::make("inflight-zset:{$connectionName}:{$queueKey}"), $uuid]);
                }

                // Batch tracking + chain-lineage click-through index —
                // uuid → failed_jobs row id. JobFailed fires AFTER
                // FailedJobProvider::log() inserts the row, so the
                // lookup-by-uuid is safe. Doubles as the source for
                // `UuidResolver::resolve` so the `↰ From` parent-lineage
                // click-through can reach a failed parent's modal even
                // when batches are disabled.
                $needsTargetIndex = Config::bool('batches.enabled', true)
                    || Config::bool('chain_lineage.enabled', true);
                if ($needsTargetIndex) {
                    $failedJobsId = $this->resolveFailedJobId($uuid);
                    if ($failedJobsId !== null) {
                        $ttl = max(
                            Config::bool('batches.enabled', true) ? Config::int('batches.ttl_seconds', 604800) : 0,
                            Config::bool('chain_lineage.enabled', true) ? Config::int('chain_lineage.lineage_ttl_seconds', 604800) : 0,
                        );
                        $redis->command('setex', [
                            KeyPrefix::make("uuid-failed:{$uuid}"),
                            $ttl,
                            (string) $failedJobsId,
                        ]);
                    }
                }

                // Initiator origin + call site — persist into the interim
                // `qi:initiator:{uuid}` hash so the failed-job modal can
                // resolve them lazily at render time (the dashboard request
                // has no job Context). Origin is read off the worker's
                // restored hidden Context; call_site is read back from the
                // same key (RecordJobQueued may already have written it
                // queue-side). Failed jobs never hit RecordJobProcessed, so
                // the key keeps its full 7d TTL for the dashboard's read.
                $origin = $this->resolveInitiatorOrigin();
                $callSite = $this->resolveInitiatorCallSite($uuid);
                if ($origin !== null || $callSite !== null) {
                    (new InitiatorStore())->write(
                        $uuid,
                        ['origin' => $origin, 'call_site' => $callSite],
                        Config::int('initiator.ttl_seconds', 604800),
                    );
                }
            }

            $this->stampClassRoster($redis, $class, $connectionName, $nowTs, $isoNow);

            // qi:class:{uuid} — uuid → class index for backward-chain lineage
            // resolution. Same write as in RecordJobProcessed; failed jobs
            // also need their class addressable by uuid because a downstream
            // child can reference a failed parent in its lineage.
            if (
                Config::bool('chain_lineage.enabled', true)
                && $uuid !== null && $uuid !== ''
            ) {
                $redis->command('setex', [
                    ParentClassResolver::classKey($uuid),
                    Config::int('chain_lineage.lineage_ttl_seconds', 604800),
                    $class,
                ]);
            }

            // Per-job failure alert. Gated internally on alerts.enabled +
            // alerts.rules.job_failed.enabled, so the call is unconditional
            // (mirrors RecordScheduledTaskFailed). Lives inside this try so a
            // dispatch throw is caught by the same Log::warning net and never
            // loses the counter writes above; the dispatcher's event/notify
            // paths are independently try-wrapped.
            $failureContext = $this->captureFailureContext($uuid);
            $this->dispatchFailureAlert($event, $class, $connectionName, $queueKey, $uuid, $failureContext);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: RecordJobFailed failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Collect the sanitized Context + environment snapshot for the failed job,
     * store it by uuid for the dashboard modal + markdown export, and return it
     * so the alert event can carry it too. Returns null when disabled. The
     * store write is skipped for an empty uuid (the read surfaces key by uuid),
     * but the snapshot is still returned for the event.
     *
     * @return array<string, mixed>|null
     */
    private function captureFailureContext(?string $uuid): ?array
    {
        if (! Config::bool('failure_context.enabled', true)) {
            return null;
        }

        $context = (new FailureContextCollector())->collect();

        if ($uuid !== null && $uuid !== '') {
            (new FailureContextStore())->write(
                $uuid,
                $context,
                Config::int('failure_context.ttl_seconds', 604800),
            );
        }

        return $context;
    }

    /**
     * Fire the per-job failure alert. Extracted so the uuid-normalisation
     * ternary stays out of `handle()`'s cognitive-complexity budget.
     *
     * @param  array<string, mixed>|null  $failureContext
     */
    private function dispatchFailureAlert(JobFailed $event, string $class, string $connectionName, string $queueKey, ?string $uuid, ?array $failureContext): void
    {
        $this->dispatcher->dispatchJobFailed(
            $class,
            $connectionName,
            $queueKey,
            $uuid !== null && $uuid !== '' ? $uuid : null,
            $event->exception,
            $failureContext,
        );
    }

    /**
     * Atomic INCR + EXPIREAT on both the aggregate and per-connection
     * counter keys. Empty `$connectionName` falls back to aggregate-only.
     */
    private function incrFailedCounters(Connection $redis, string $class, string $connectionName, string $bucket, int $expireAt): void
    {
        if ($connectionName === '') {
            $key = KeyPrefix::make("failed:{$class}:{$bucket}");
            $redis->command('incr', [$key]);
            $redis->command('expireat', [$key, $expireAt]);

            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::incrPairWithExpire(),
            2,
            KeyPrefix::make("failed:{$class}:{$bucket}"),
            KeyPrefix::make("failed:{$class}:{$connectionName}:{$bucket}"),
            (string) $expireAt,
        );
    }

    /**
     * Monotonic INCR counters powering the Prometheus exporter's
     * `queue_insights_jobs_failed_total` metric. Symmetric to
     * `RecordJobProcessed::writeProcessedMonotonic` — see that method
     * for the full design rationale (TTL-on-write avoids a race with
     * the snapshot prune that earlier drafts had).
     */
    private function writeFailedMonotonic(Connection $redis, string $class, string $connectionName): void
    {
        $aggregate = KeyPrefix::classKey('failed-total', $class);
        $redis->command('incr', [$aggregate]);
        $redis->command('expire', [$aggregate, self::MONOTONIC_TTL_SECONDS]);

        if ($connectionName !== '') {
            $perConnection = KeyPrefix::classKey('failed-total', $class, $connectionName);
            $redis->command('incr', [$perConnection]);
            $redis->command('expire', [$perConnection, self::MONOTONIC_TTL_SECONDS]);
        }
    }

    /**
     * Stamp the aggregate + per-connection class rosters and the dual-write
     * `last_run` keys. Empty `$connectionName` falls back to aggregate-only.
     */
    private function stampClassRoster(Connection $redis, string $class, string $connectionName, int $nowTs, string $isoNow): void
    {
        if ($connectionName === '') {
            $redis->command('zadd', [KeyPrefix::make('classes'), $nowTs, $class]);
            $redis->command('setex', [KeyPrefix::classKey('last_run', $class), 2592000, $isoNow]);

            return;
        }

        RedisEval::exec(
            $redis,
            LuaScripts::classesRoster(),
            2,
            KeyPrefix::make('classes'),
            KeyPrefix::make("classes:{$connectionName}"),
            (string) $nowTs,
            $class,
            (string) 2592000,
        );

        RedisEval::exec(
            $redis,
            LuaScripts::setexPair(),
            2,
            KeyPrefix::classKey('last_run', $class),
            KeyPrefix::classKey('last_run', $class, $connectionName),
            (string) 2592000,
            $isoNow,
        );
    }

    /**
     * Read the coarse initiator origin off the worker's restored hidden
     * `Context`. Null when initiator capture is off or no origin was
     * stamped at dispatch time.
     */
    private function resolveInitiatorOrigin(): ?string
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_origin', true)) {
            return null;
        }

        $origin = Context::getHidden(Config::string('initiator.context_key', 'qi_origin'));

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * Read the dispatch call site out of the interim `qi:initiator:{uuid}`
     * hash — RecordJobQueued may already have stamped it queue-side. Null
     * when initiator capture or call-site capture is off, or no call site
     * was resolved at dispatch time.
     */
    private function resolveInitiatorCallSite(string $uuid): ?string
    {
        if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_call_site', false)) {
            return null;
        }

        return (new InitiatorStore())->read($uuid)['call_site'];
    }

    /**
     * Look up the failed_jobs row id for a uuid. Wrapped in try/catch so a
     * non-default failed-job storage (custom provider, missing table) only
     * silently skips the batch-tracking write — never breaks the listener.
     *
     * `orderByDesc('id')` because Laravel's `DatabaseUuidFailedJobProvider`
     * inserts a fresh row on every JobFailed, so a uuid that's been retried
     * and failed again has multiple rows. The just-inserted one (highest id)
     * is the one this event is for; without the order-by we'd index the
     * oldest row and the batch-detail click would open the wrong failure.
     */
    private function resolveFailedJobId(string $uuid): ?int
    {
        try {
            $id = DB::table('failed_jobs')->where('uuid', $uuid)->orderByDesc('id')->value('id');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }
}
