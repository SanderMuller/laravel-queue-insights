<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Support\ConfigValidator;
use SanderMuller\QueueInsights\Support\SchedulerConfigValidator;
use SanderMuller\QueueInsights\Support\SnapshotCadence;

/**
 * @return list<string>
 */
function cadenceExpressionsFor(Schedule $schedule, string $command): array
{
    $expressions = [];
    foreach ($schedule->events() as $event) {
        if ($event instanceof Event && str_contains((string) $event->command, $command)) {
            $expressions[] = $event->expression;
        }
    }

    return $expressions;
}

it('registers the snapshot command every minute by default', function (): void {
    $schedule = $this->app->make(Schedule::class);

    expect(cadenceExpressionsFor($schedule, 'queue-insights:snapshot'))->toBe(['* * * * *']);
});

it('honours a custom snapshot cron', function (): void {
    config()->set('queue-insights.schedule.cron', '*/15 * * * *');

    $schedule = $this->app->make(Schedule::class);

    expect(cadenceExpressionsFor($schedule, 'queue-insights:snapshot'))->toBe(['*/15 * * * *']);
});

it('honours a custom sweeper cron', function (): void {
    config()->set('queue-insights.scheduler.enabled', true);
    config()->set('queue-insights.scheduler.sweeper.cron', '*/5 * * * *');

    $schedule = $this->app->make(Schedule::class);

    expect(cadenceExpressionsFor($schedule, 'queue-insights:schedule:sweep'))->toBe(['*/5 * * * *']);
});

it('derives the live key ttl from the time left until the next fire', function (): void {
    expect(SnapshotCadence::liveTtlSeconds())->toBe(90);

    config()->set('queue-insights.schedule.cron', '*/15 * * * *');
    expect(SnapshotCadence::liveTtlSeconds())->toBeGreaterThanOrEqual(90)
        ->toBeLessThanOrEqual(930);

    config()->set('queue-insights.schedule.live_ttl_seconds', 300);
    expect(SnapshotCadence::liveTtlSeconds())->toBe(300);
});

it('sizes the live key ttl across an idle stretch of a gapped cron', function (): void {
    // Friday 17:59 for an office-hours cron: the next fire is Monday 09:00,
    // so a TTL sized on the in-hours one-minute period would expire all
    // weekend and flap the watchdog.
    config()->set('app.schedule_timezone', 'UTC');
    $friday = strtotime('2025-01-03 17:59:00 UTC');

    expect(SnapshotCadence::secondsUntilNextFire('* 9-17 * * 1-5', $friday))
        ->toBe((strtotime('2025-01-06 09:00:00 UTC')) - $friday);
});

it('evaluates the next fire in the scheduler timezone, not PHP default', function (): void {
    // 2025-01-03 17:59 New York = 22:59 UTC; the next office-hours fire is
    // Monday 09:00 New York. Evaluated in UTC the expression would instead
    // still be inside 9-17, under-sizing the TTL by hours.
    config()->set('app.schedule_timezone', 'America/New_York');
    $friday = strtotime('2025-01-03 22:59:00 UTC');

    expect(SnapshotCadence::secondsUntilNextFire('* 9-17 * * 1-5', $friday))
        ->toBe((strtotime('2025-01-06 14:00:00 UTC')) - $friday);
});

it('sizes a monthly cadence past the weekly mark', function (): void {
    // A monthly snapshot must not read as dead for the rest of the month.
    config()->set('app.schedule_timezone', 'UTC');

    expect(SnapshotCadence::secondsUntilNextFire('0 0 1 * *', strtotime('2025-01-02 00:00:00 UTC')))
        ->toBe(30 * 86400);
});

it('falls back to the default cron for a blank value', function (): void {
    config()->set('queue-insights.schedule.cron', '   ');

    expect(SnapshotCadence::snapshotCron())->toBe('* * * * *');
});

it('rejects an invalid snapshot cron', function (): void {
    ConfigValidator::validateSchedule(['cron' => 'every 15 minutes']);
})->throws(QueueInsightsConfigException::class, 'queue-insights.schedule.cron');

it('rejects a non-positive live ttl', function (): void {
    ConfigValidator::validateSchedule(['live_ttl_seconds' => 0]);
})->throws(QueueInsightsConfigException::class, 'queue-insights.schedule.live_ttl_seconds');

it('accepts a valid schedule block', function (): void {
    ConfigValidator::validateSchedule(['enabled' => true, 'cron' => '*/15 * * * *', 'live_ttl_seconds' => null]);
})->throwsNoExceptions();

it('rejects an invalid sweeper cron', function (): void {
    SchedulerConfigValidator::validate(['sweeper' => ['enabled' => true, 'cron' => 'nope']]);
})->throws(QueueInsightsConfigException::class, 'queue-insights.scheduler.sweeper.cron');
