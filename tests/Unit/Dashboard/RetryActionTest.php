<?php declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use SanderMuller\QueueInsights\Dashboard\AuditContext;
use SanderMuller\QueueInsights\Dashboard\RetryAction;
use SanderMuller\QueueInsights\Dashboard\RetryActor;
use SanderMuller\QueueInsights\Dashboard\RetryStatus;
use SanderMuller\QueueInsights\Tests\Support\RecordingConsoleKernel;

/**
 * Test seam: replace the bound Console Kernel with a recorder so
 * `Artisan::call('queue:retry', ...)` lands in `RecordingConsoleKernel::$calls`
 * instead of running a real console command. Mirrors RetryFailedTest's
 * harness; the unit test does not need Livewire's component lifecycle.
 */
beforeEach(function (): void {
    RecordingConsoleKernel::reset();
    app()->instance(ConsoleKernelContract::class, new RecordingConsoleKernel());
    RateLimiterFacade::clear('qi.retry:test-actor');
});

function retryAction(?LoggerInterface $log = null): RetryAction
{
    if (! $log instanceof LoggerInterface) {
        /** @var LoggerInterface&MockInterface $spy */
        $spy = Mockery::spy(LoggerInterface::class);
        $log = $spy;
    }

    return new RetryAction(resolve(RateLimiter::class), $log);
}

function retryActor(string $key = 'qi.retry:test-actor', int|string|null $userId = 42): RetryActor
{
    return new RetryActor($userId, $key);
}

function auditContext(): AuditContext
{
    return new AuditContext(
        userId: 42,
        scopeConnection: 'redis',
        filterConnection: 'redis',
        filterQueue: 'video',
        filterClass: 'App\\Jobs\\Encode',
        filterFrom: '',
        filterTo: '',
    );
}

it('consumeRateLimit returns null when budget is available and consumes one hit', function (): void {
    $action = retryAction();
    $actor = retryActor();

    expect($action->consumeRateLimit($actor))->toBeNull()
        ->and(RateLimiterFacade::attempts($actor->rateLimitKey))
        ->toBe(1);
});

it('consumeRateLimit returns RateLimited outcome after 30 attempts and does not consume', function (): void {
    $action = retryAction();
    $actor = retryActor();

    for ($i = 0; $i < 30; ++$i) {
        RateLimiterFacade::hit($actor->rateLimitKey, 60);
    }

    $outcome = $action->consumeRateLimit($actor);

    expect($outcome)->not->toBeNull()
        ->and($outcome->status)->toBe(RetryStatus::RateLimited)
        ->and($outcome->message)->toBe('Retry rate limit reached (30/min). Try again shortly.');
});

it('single returns RateLimited without calling Artisan when budget is exhausted', function (): void {
    $action = retryAction();
    $actor = retryActor();

    for ($i = 0; $i < 30; ++$i) {
        RateLimiterFacade::hit($actor->rateLimitKey, 60);
    }

    $outcome = $action->single('uuid-x', $actor, auditContext());

    expect($outcome->status)->toBe(RetryStatus::RateLimited)
        ->and(RecordingConsoleKernel::$calls)->toBeEmpty();
});

it('single returns Ok with "Retry dispatched." on exit 0', function (): void {
    $action = retryAction();

    $outcome = $action->single('uuid-x', retryActor(), auditContext());

    expect($outcome->status)->toBe(RetryStatus::Ok)
        ->and($outcome->message)->toBe('Retry dispatched.')
        ->and(RecordingConsoleKernel::$calls)->toHaveCount(1)
        ->and(RecordingConsoleKernel::$calls[0]['command'])->toBe('queue:retry')
        ->and(RecordingConsoleKernel::$calls[0]['params'])->toBe(['id' => ['uuid-x']]);
});

it('single returns NonZeroExit with the single-path message when queue:retry returns non-zero', function (): void {
    RecordingConsoleKernel::$nextExitCode = 1;
    $action = retryAction();

    $outcome = $action->single('uuid-x', retryActor(), auditContext());

    expect($outcome->status)->toBe(RetryStatus::NonZeroExit)
        ->and($outcome->message)->toBe('Retry could not be dispatched (queue:retry returned non-zero — already retried, missing, or driver rejected).');
});

it('bulk returns Ok with pluralised message on exit 0 (multiple uuids)', function (): void {
    $action = retryAction();

    $outcome = $action->bulk(['uuid-a', 'uuid-b'], retryActor(), auditContext());

    expect($outcome->status)->toBe(RetryStatus::Ok)
        ->and($outcome->message)->toBe('Retried 2 jobs.')
        ->and(RecordingConsoleKernel::$calls)->toHaveCount(1)
        ->and(RecordingConsoleKernel::$calls[0]['params'])->toBe(['id' => ['uuid-a', 'uuid-b']]);
});

it('bulk returns Ok with singular message when count is 1', function (): void {
    $action = retryAction();

    $outcome = $action->bulk(['uuid-a'], retryActor(), auditContext());

    expect($outcome->status)->toBe(RetryStatus::Ok)
        ->and($outcome->message)->toBe('Retried 1 job.');
});

it('bulk returns NonZeroExit with the bulk-path sprintf phrasing', function (): void {
    RecordingConsoleKernel::$nextExitCode = 2;
    $action = retryAction();

    $outcome = $action->bulk(['uuid-a', 'uuid-b'], retryActor(), auditContext());

    expect($outcome->status)->toBe(RetryStatus::NonZeroExit)
        ->and($outcome->message)->toBe('Bulk retry returned non-zero exit 2 — some rows may have been already retried, missing, or rejected by the driver. Check logs.');
});

it('bulk does NOT consume rate-limit (component pre-flighted)', function (): void {
    $action = retryAction();
    $actor = retryActor();

    $action->bulk(['uuid-a'], $actor, auditContext());

    // Action::bulk must not call hit() — component owns that for bulk path.
    expect(RateLimiterFacade::attempts($actor->rateLimitKey))->toBe(0);
});

it('audit log emits queue-insights.retry with the byte-identical key set + sanitised filter shape', function (): void {
    /** @var LoggerInterface&MockInterface $log */
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('info')
        ->once()
        ->with('queue-insights.retry', Mockery::on(fn (array $context): bool => $context === [
            'kind' => 'bulk',
            'uuids' => ['uuid-a', 'uuid-b'],
            'count' => 2,
            'user_id' => 42,
            'scope_connection' => 'redis',
            'filters' => [
                'connection' => 'redis',
                'queue' => 'video',
                'class' => 'App\\Jobs\\Encode',
                'from' => '',
                'to' => '',
            ],
        ]));

    $action = new RetryAction(resolve(RateLimiter::class), $log);
    $action->bulk(['uuid-a', 'uuid-b'], retryActor(), auditContext());
});

it('uses the actor rateLimitKey verbatim — no hidden Auth-derived key inside the action', function (): void {
    $action = retryAction();
    $opaqueKey = 'qi.retry:opaque-' . bin2hex(random_bytes(4));

    for ($i = 0; $i < 30; ++$i) {
        RateLimiterFacade::hit($opaqueKey, 60);
    }

    $outcome = $action->single('uuid-x', retryActor($opaqueKey, null), auditContext());

    expect($outcome->status)->toBe(RetryStatus::RateLimited);
});

it('throws inside dispatch surface a Threw outcome with the generic check-logs message', function (): void {
    // Force `Artisan::call` to throw by binding a Mockery kernel that explodes.
    /** @var ConsoleKernelContract&MockInterface $kernel */
    $kernel = Mockery::mock(ConsoleKernelContract::class);
    $kernel->shouldReceive('call')->andThrow(new RuntimeException('boom'));
    app()->instance(ConsoleKernelContract::class, $kernel);

    $action = retryAction();

    $singleOutcome = $action->single('uuid-x', retryActor(), auditContext());
    expect($singleOutcome->status)->toBe(RetryStatus::Threw)
        ->and($singleOutcome->message)->toBe('Retry failed — check logs.');

    $bulkOutcome = $action->bulk(['uuid-a'], retryActor(), auditContext());
    expect($bulkOutcome->status)->toBe(RetryStatus::Threw)
        ->and($bulkOutcome->message)->toBe('Bulk retry failed — check logs.');
});
