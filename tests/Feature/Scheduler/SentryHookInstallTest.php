<?php declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Support\Facades\Event;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use SanderMuller\QueueInsights\Support\SentryExceptionEventRegistry;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event as SentryEvent;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

use function Sentry\captureException;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    Event::forget(ScheduledTaskStarting::class);

    $this->previousHub = SentrySdk::getCurrentHub();
});

afterEach(function (): void {
    if ($this->previousHub instanceof HubInterface) {
        SentrySdk::setCurrentHub($this->previousHub);
    }
});

function sentryHookClient(): ClientInterface
{
    $transport = new class implements TransportInterface {
        public function send(SentryEvent $event): Result
        {
            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    return ClientBuilder::create(['dsn' => 'https://public@sentry.example.test/1'])
        ->setTransport($transport)
        ->getClient();
}

function fireScheduledTaskStarting(): void
{
    $task = new ScheduleEvent(resolve(CacheEventMutex::class), 'php artisan demo:run');
    $task->expression = '* * * * *';

    event(new ScheduledTaskStarting($task));
}

it('links the Sentry event when the Sentry provider boots after this one', function (): void {
    config()->set('queue-insights.scheduler.enabled', true);

    // Package discovery boots this provider before sentry/sentry-laravel,
    // which only sets its hub during its own boot.
    SentrySdk::setCurrentHub(new Hub());
    (new QueueInsightsServiceProvider(app()))->boot();
    SentrySdk::setCurrentHub(new Hub(sentryHookClient()));

    fireScheduledTaskStarting();

    $exception = new RuntimeException('task failed');
    captureException($exception);

    expect(SentryExceptionEventRegistry::get($exception))->toBeString()->not->toBeEmpty();
});

it('wraps beforeSend only once across many task starts', function (): void {
    config()->set('queue-insights.scheduler.enabled', true);
    $client = sentryHookClient();
    SentrySdk::setCurrentHub(new Hub($client));
    (new QueueInsightsServiceProvider(app()))->boot();

    fireScheduledTaskStarting();
    $first = $client->getOptions()->getBeforeSendCallback();
    fireScheduledTaskStarting();

    expect($client->getOptions()->getBeforeSendCallback())->toBe($first);
});

it('leaves the Sentry client alone when scheduler observability is off', function (): void {
    config()->set('queue-insights.scheduler.enabled', false);
    $client = sentryHookClient();
    $original = $client->getOptions()->getBeforeSendCallback();
    SentrySdk::setCurrentHub(new Hub($client));

    (new QueueInsightsServiceProvider(app()))->boot();
    fireScheduledTaskStarting();

    expect($client->getOptions()->getBeforeSendCallback())->toBe($original);
});
