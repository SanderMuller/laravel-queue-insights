<?php declare(strict_types=1);

use DG\BypassFinals;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use SanderMuller\QueueInsights\Support\RedisEval;
use SanderMuller\QueueInsights\Support\SentryExceptionEventRegistry;
use SanderMuller\QueueInsights\Tests\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event as SentryEvent;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

// Strip `final` from a tightly-scoped allowlist of package classes
// during the test run so Mockery can substitute `final readonly class`
// services. Process-wide enable is too broad — it would let tests
// silently start mocking final classes that real consumers can never
// construct, hiding package-boundary regressions until release. The
// allowlist below gates which file paths get bytecode-rewritten;
// everything else keeps `final` in the test process and matches the
// shipped artifact exactly.
BypassFinals::enable();
BypassFinals::allowPaths([
    '*/src/QueueInsights.php',
]);

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Driver-agnostic XADD helper for test seeding — phpredis and Predis have different
 * xAdd() signatures, so route through RedisEval::exec() to keep one code path.
 *
 * @param  array<string, string>  $fields
 */
function seedStream(RedisConnection $redis, string $key, array $fields, string $id = '*'): void
{
    $flat = [];
    foreach ($fields as $k => $v) {
        $flat[] = $k;
        $flat[] = $v;
    }

    RedisEval::exec(
        $redis,
        "return redis.call('XADD', KEYS[1], ARGV[1], unpack(ARGV, 2))",
        1,
        $key,
        $id,
        ...$flat,
    );
}

/**
 * Run $send against a spy Sentry hub that has a bound client (a no-op
 * transport recording sent events) and return the captured events. The test
 * environment installs no Sentry hub by default — `SentryAvailability::available()`
 * is false until a client is bound — so any test exercising the sentry channel's
 * available path must run inside this helper. The process-global hub is always
 * restored, even when $send throws.
 *
 * @param  Closure():void  $send
 * @return list<SentryEvent>
 */
function withBoundSentryHub(Closure $send): array
{
    $transport = new class implements TransportInterface {
        /** @var list<SentryEvent> */
        public array $events = [];

        public function send(SentryEvent $event): Result
        {
            $this->events[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    $client = ClientBuilder::create(['dsn' => 'https://public@sentry.example.test/1'])
        ->setTransport($transport)
        ->getClient();

    $previous = SentrySdk::getCurrentHub();
    SentrySdk::setCurrentHub(new Hub($client));
    SentryExceptionEventRegistry::installBeforeSendHook();

    try {
        $send();
    } finally {
        SentrySdk::setCurrentHub($previous);
    }

    return $transport->events;
}
