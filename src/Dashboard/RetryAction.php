<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owns the failed-job retry pipeline (single + bulk) extracted from the
 * Livewire dashboard god-component: rate-limit consumption, queue:retry
 * dispatch, exit-code branch, audit-log emission. Returns a typed
 * outcome; the Livewire caller maps that to Session::flash + state reset.
 *
 * Authorisation (`retryFailedJobs` Gate) and pre-flight bulk validation
 * (empty filters / collector / count >100) stay at the Livewire boundary.
 *
 * @internal
 */
final readonly class RetryAction
{
    private const int RATE_LIMIT_ATTEMPTS = 30;

    private const int RATE_LIMIT_DECAY_SECONDS = 60;

    private const string RATE_LIMIT_MESSAGE = 'Retry rate limit reached (30/min). Try again shortly.';

    public function __construct(
        private RateLimiter $limiter,
        private LoggerInterface $log,
    ) {}

    /**
     * Consume one rate-limit attempt. Returns a `RateLimited` outcome when
     * the budget is exhausted (caller flashes the message), or null when
     * the attempt is permitted. Used by the Livewire bulk pre-flight to
     * preserve today's "limit before collector" anti-DoS ordering.
     */
    public function consumeRateLimit(RetryActor $actor): ?RetryOutcome
    {
        if ($this->limiter->tooManyAttempts($actor->rateLimitKey, self::RATE_LIMIT_ATTEMPTS)) {
            return new RetryOutcome(RetryStatus::RateLimited, self::RATE_LIMIT_MESSAGE);
        }

        $this->limiter->hit($actor->rateLimitKey, self::RATE_LIMIT_DECAY_SECONDS);

        return null;
    }

    public function single(string $uuid, RetryActor $actor, AuditContext $audit): RetryOutcome
    {
        if (($limited = $this->consumeRateLimit($actor)) instanceof RetryOutcome) {
            return $limited;
        }

        try {
            $exit = Artisan::call('queue:retry', ['id' => [$uuid]]);

            if ($exit !== 0) {
                $this->log->warning('queue-insights.retry.exit_nonzero', [
                    'kind' => 'single',
                    'uuid' => $uuid,
                    'exit' => $exit,
                ]);

                return new RetryOutcome(
                    RetryStatus::NonZeroExit,
                    'Retry could not be dispatched (queue:retry returned non-zero — already retried, missing, or driver rejected).',
                );
            }

            $this->writeAuditLog('single', [$uuid], $audit);

            return new RetryOutcome(RetryStatus::Ok, 'Retry dispatched.');
        } catch (Throwable $throwable) {
            $this->log->warning('queue-insights: retryFailed threw', [
                'uuid' => $uuid,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return new RetryOutcome(RetryStatus::Threw, 'Retry failed — check logs.');
        }
    }

    /**
     * @param  list<string>  $uuids
     */
    public function bulk(array $uuids, RetryActor $actor, AuditContext $audit): RetryOutcome
    {
        $count = count($uuids);

        try {
            $exit = Artisan::call('queue:retry', ['id' => $uuids]);

            if ($exit !== 0) {
                $this->log->warning('queue-insights.retry.exit_nonzero', [
                    'kind' => 'bulk',
                    'count' => $count,
                    'exit' => $exit,
                ]);

                return new RetryOutcome(
                    RetryStatus::NonZeroExit,
                    sprintf(
                        'Bulk retry returned non-zero exit %d — some rows may have been already retried, missing, or rejected by the driver. Check logs.',
                        $exit,
                    ),
                );
            }

            $this->writeAuditLog('bulk', $uuids, $audit);

            return new RetryOutcome(
                RetryStatus::Ok,
                sprintf('Retried %d job%s.', $count, $count === 1 ? '' : 's'),
            );
        } catch (Throwable $throwable) {
            $this->log->warning('queue-insights: retryFailedBulk threw', [
                'count' => $count,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return new RetryOutcome(RetryStatus::Threw, 'Bulk retry failed — check logs.');
        }
    }

    /**
     * @param  list<string>  $uuids
     */
    private function writeAuditLog(string $kind, array $uuids, AuditContext $audit): void
    {
        $this->log->info('queue-insights.retry', [
            'kind' => $kind,
            'uuids' => $uuids,
            'count' => count($uuids),
            'user_id' => $audit->userId,
            'scope_connection' => $audit->scopeConnection,
            'filters' => [
                'connection' => $audit->filterConnection,
                'queue' => $audit->filterQueue,
                'class' => $audit->filterClass,
                'from' => $audit->filterFrom,
                'to' => $audit->filterTo,
            ],
        ]);
    }
}
