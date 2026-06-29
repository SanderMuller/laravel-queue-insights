<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\SentryExceptionEventRegistry;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

use function Sentry\captureException;

/**
 * Build a no-op Sentry transport that discards sent events (sufficient for
 * tests that only care about registry state, not what was transmitted).
 */
function makeSentryRegistryTransport(): TransportInterface
{
    return new class implements TransportInterface {
        public function send(Event $event): Result
        {
            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };
}

it('get returns null for an exception that was never captured', function (): void {
    expect(SentryExceptionEventRegistry::get(new RuntimeException('never seen')))->toBeNull();
});

it('keys by object identity — two distinct instances with the same message do not collide', function (): void {
    $a = new RuntimeException('same message');
    $b = new RuntimeException('same message');

    SentryExceptionEventRegistry::record($a, 'event-a');

    expect(SentryExceptionEventRegistry::get($a))->toBe('event-a')
        ->and(SentryExceptionEventRegistry::get($b))->toBeNull();
});

it('records the event id when beforeSend passes the event through', function (): void {
    $client = ClientBuilder::create(['dsn' => 'https://public@sentry.example.test/1'])
        ->setTransport(makeSentryRegistryTransport())
        ->getClient();

    $previous = SentrySdk::getCurrentHub();
    SentrySdk::setCurrentHub(new Hub($client));
    SentryExceptionEventRegistry::installBeforeSendHook();

    try {
        $exception = new RuntimeException('captured');
        captureException($exception);

        expect(SentryExceptionEventRegistry::get($exception))->toBeString()->not->toBeEmpty();
    } finally {
        SentrySdk::setCurrentHub($previous);
    }
});

it('does not record when the beforeSend chain discards the event', function (): void {
    $client = ClientBuilder::create(['dsn' => 'https://public@sentry.example.test/1'])
        ->setTransport(makeSentryRegistryTransport())
        ->getClient();

    // A user-supplied beforeSend that discards every event: the registry
    // hook must respect the discard and not store a stale event id.
    $client->getOptions()->setBeforeSendCallback(
        static function (Event $event, ?EventHint $hint): ?Event {
            return null;
        },
    );

    $previous = SentrySdk::getCurrentHub();
    SentrySdk::setCurrentHub(new Hub($client));
    SentryExceptionEventRegistry::installBeforeSendHook();

    try {
        $exception = new RuntimeException('discarded by user beforeSend');
        captureException($exception);

        expect(SentryExceptionEventRegistry::get($exception))->toBeNull();
    } finally {
        SentrySdk::setCurrentHub($previous);
    }
});
