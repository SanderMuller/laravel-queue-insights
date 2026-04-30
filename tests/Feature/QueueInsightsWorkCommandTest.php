<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanderMuller\QueueInsights\Console\DefaultWorkerProcessFactory;
use SanderMuller\QueueInsights\Console\WorkerOutputStreams;
use SanderMuller\QueueInsights\Console\WorkerProcessFactory;
use Symfony\Component\Process\Process;

final class RecordingWorkerFactory implements WorkerProcessFactory
{
    /** @var list<array{connection: string, queues: list<string>, flags: array<string, string|true>}> */
    public array $calls = [];

    public function make(string $connection, array $queues, array $forwardedFlags): Process
    {
        $this->calls[] = [
            'connection' => $connection,
            'queues' => $queues,
            'flags' => $forwardedFlags,
        ];

        return new Process(['true']);
    }
}

final class StubWorkerFactory implements WorkerProcessFactory
{
    /** @var array<string, array<string, string>> */
    public array $envByConnection = [];

    public function make(string $connection, array $queues, array $forwardedFlags): Process
    {
        $env = $this->envByConnection[$connection] ?? [];
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/StubWorker.php', $connection, ...$queues],
            null,
            $env,
        );
        $process->setTimeout(null);

        return $process;
    }
}

final class CapturedWorkerStreams implements WorkerOutputStreams
{
    /** @var resource */
    public mixed $out;

    /** @var resource */
    public mixed $err;

    public function __construct()
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        if ($out === false || $err === false) {
            throw new RuntimeException('failed to open memory streams');
        }

        $this->out = $out;
        $this->err = $err;
    }

    public function stdout(): mixed
    {
        return $this->out;
    }

    public function stderr(): mixed
    {
        return $this->err;
    }

    public function readStdout(): string
    {
        rewind($this->out);

        return stream_get_contents($this->out) ?: '';
    }

    public function readStderr(): string
    {
        rewind($this->err);

        return stream_get_contents($this->err) ?: '';
    }
}

beforeEach(function (): void {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('pcntl required for queue-insights:work tests');
    }

    $this->factory = new RecordingWorkerFactory();
    $this->app->instance(WorkerProcessFactory::class, $this->factory);
});

it('refuses to boot when snapshots are empty', function (): void {
    config()->set('queue-insights.snapshots', []);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('no monitored queues configured');
    expect($this->factory->calls)->toBe([]);
});

it('groups snapshots by connection and preserves queue order', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'high'],
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0);
    expect($this->factory->calls)->toHaveCount(2);
    expect($this->factory->calls[0]['connection'])->toBe('sqs');
    expect($this->factory->calls[0]['queues'])->toBe(['high', 'default']);
    expect($this->factory->calls[1]['connection'])->toBe('redis');
    expect($this->factory->calls[1]['queues'])->toBe(['default', 'mail']);
});

it('skips malformed snapshot entries silently', function (): void {
    config()->set('queue-insights.snapshots', [
        'not-an-array',
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'sqs'],
        ['queue' => 'lonely'],
        ['connection' => 42, 'queue' => 'default'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0);
    expect($this->factory->calls)->toHaveCount(1);
    expect($this->factory->calls[0]['connection'])->toBe('sqs');
    expect($this->factory->calls[0]['queues'])->toBe(['default']);
});

it('forwards value flags exactly when supplied', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);

    $exit = Artisan::call('queue-insights:work', [
        '--tries' => '5',
        '--timeout' => '90',
        '--memory' => '256',
        '--sleep' => '3',
        '--rest' => '0',
        '--max-jobs' => '100',
        '--max-time' => '3600',
        '--backoff' => '10',
        '--name' => 'supervisor-1',
    ]);

    expect($exit)->toBe(0);
    expect($this->factory->calls[0]['flags'])->toBe([
        'tries' => '5',
        'timeout' => '90',
        'memory' => '256',
        'sleep' => '3',
        'rest' => '0',
        'max-jobs' => '100',
        'max-time' => '3600',
        'backoff' => '10',
        'name' => 'supervisor-1',
    ]);
});

it('omits flags that were not supplied', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);

    Artisan::call('queue-insights:work', ['--tries' => '3']);

    expect($this->factory->calls[0]['flags'])->toBe(['tries' => '3']);
});

it('forwards boolean flags as presence-only', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);

    Artisan::call('queue-insights:work', [
        '--once' => true,
        '--stop-when-empty' => true,
        '--force' => true,
    ]);

    expect($this->factory->calls[0]['flags'])->toBe([
        'once' => true,
        'stop-when-empty' => true,
        'force' => true,
    ]);
});

it('narrows fan-out via --connection= (single value)', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);

    Artisan::call('queue-insights:work', ['--connection' => ['redis']]);

    expect($this->factory->calls)->toHaveCount(1);
    expect($this->factory->calls[0]['connection'])->toBe('redis');
});

it('narrows fan-out via repeated --connection= (array form)', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
        ['connection' => 'beanstalk', 'queue' => 'low'],
    ]);

    Artisan::call('queue-insights:work', [
        '--connection' => ['sqs', 'beanstalk'],
    ]);

    expect($this->factory->calls)->toHaveCount(2);
    expect(array_column($this->factory->calls, 'connection'))->toBe(['sqs', 'beanstalk']);
});

it('narrows fan-out via CSV --connection= (single value, comma-split)', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
        ['connection' => 'beanstalk', 'queue' => 'low'],
    ]);

    Artisan::call('queue-insights:work', [
        '--connection' => ['sqs,redis'],
    ]);

    expect($this->factory->calls)->toHaveCount(2);
    expect(array_column($this->factory->calls, 'connection'))->toBe(['sqs', 'redis']);
});

it('composes array + CSV in --connection= and dedups first-seen', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
        ['connection' => 'beanstalk', 'queue' => 'low'],
    ]);

    Artisan::call('queue-insights:work', [
        '--connection' => ['sqs,redis', 'sqs', ' beanstalk '],
    ]);

    expect($this->factory->calls)->toHaveCount(3);
    expect(array_column($this->factory->calls, 'connection'))
        ->toBe(['sqs', 'redis', 'beanstalk']);
});

it('refuses when --connection= matches nothing and lists configured connections', function (): void {
    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);

    $exit = Artisan::call('queue-insights:work', [
        '--connection' => ['nope'],
    ]);

    expect($exit)->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('--connection=nope');
    expect($output)->toContain('matched no monitored connections');
    expect($output)->toContain('sqs');
    expect($output)->toContain('redis');
    expect($this->factory->calls)->toBe([]);
});

it('default factory builds queue:work argv with timeout disabled', function (): void {
    $factory = new DefaultWorkerProcessFactory();

    $process = $factory->make(
        'sqs',
        ['high', 'default'],
        [
            'tries' => '5',
            'timeout' => '90',
            'name' => 'super',
            'once' => true,
            'force' => true,
        ],
    );

    expect($process->getTimeout())->toBeNull();

    $cmd = $process->getCommandLine();
    expect($cmd)->toContain('queue:work');
    expect($cmd)->toContain('sqs');
    expect($cmd)->toContain('--queue=high,default');
    expect($cmd)->toContain('--tries=5');
    expect($cmd)->toContain('--timeout=90');
    expect($cmd)->toContain('--name=super');
    expect($cmd)->toContain('--once');
    expect($cmd)->toContain('--force');
});

it('spawns one child per connection and prefixes stdout per [conn] tag', function (): void {
    $factory = new StubWorkerFactory();
    $factory->envByConnection = [
        'sqs' => ['STUB_OUT' => 'hello-from-sqs'],
        'redis' => ['STUB_OUT' => 'hello-from-redis'],
    ];
    $streams = new CapturedWorkerStreams();
    $this->app->instance(WorkerProcessFactory::class, $factory);
    $this->app->instance(WorkerOutputStreams::class, $streams);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0);
    $stdout = $streams->readStdout();
    expect($stdout)->toContain("[sqs] hello-from-sqs\n");
    expect($stdout)->toContain("[redis] hello-from-redis\n");
});

it('routes stderr lines through the [conn] prefix on the parent stderr stream', function (): void {
    $factory = new StubWorkerFactory();
    $factory->envByConnection = [
        'sqs' => ['STUB_ERR' => 'oops-on-sqs'],
    ];
    $streams = new CapturedWorkerStreams();
    $this->app->instance(WorkerProcessFactory::class, $factory);
    $this->app->instance(WorkerOutputStreams::class, $streams);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0);
    expect($streams->readStderr())->toBe("[sqs] oops-on-sqs\n");
    expect($streams->readStdout())->toBe('');
});

it('flushes a partial-line tail when the child exits', function (): void {
    $factory = new StubWorkerFactory();
    $factory->envByConnection = [
        'sqs' => ['STUB_PARTIAL' => 'unterminated-tail'],
    ];
    $streams = new CapturedWorkerStreams();
    $this->app->instance(WorkerProcessFactory::class, $factory);
    $this->app->instance(WorkerOutputStreams::class, $streams);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0);
    expect($streams->readStdout())->toBe("[sqs] unterminated-tail\n");
});

it('propagates the first non-zero child exit and terminates the survivor', function (): void {
    $factory = new StubWorkerFactory();
    $factory->envByConnection = [
        'sqs' => ['STUB_EXIT' => '7'],
        'redis' => ['STUB_TRAP' => '1', 'STUB_SLEEP' => '20'],
    ];
    $streams = new CapturedWorkerStreams();
    $this->app->instance(WorkerProcessFactory::class, $factory);
    $this->app->instance(WorkerOutputStreams::class, $streams);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);

    $start = microtime(true);
    $exit = Artisan::call('queue-insights:work');
    $duration = microtime(true) - $start;

    expect($exit)->toBe(7);
    expect($duration)->toBeLessThan(10.0);
    expect($streams->readStdout())->toContain("[redis] caught:SIGTERM\n");
});

it('returns 0 when every child exits 0', function (): void {
    $factory = new StubWorkerFactory();
    $factory->envByConnection = [
        'sqs' => ['STUB_EXIT' => '0'],
        'redis' => ['STUB_EXIT' => '0'],
    ];
    $streams = new CapturedWorkerStreams();
    $this->app->instance(WorkerProcessFactory::class, $factory);
    $this->app->instance(WorkerOutputStreams::class, $streams);

    config()->set('queue-insights.snapshots', [
        ['connection' => 'sqs', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
    ]);

    $exit = Artisan::call('queue-insights:work');

    expect($exit)->toBe(0);
});
