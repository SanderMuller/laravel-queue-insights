<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Console\WorkerOutputPrefixer;
use Symfony\Component\Process\Process;

function makePrefixerStreams(): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');

    return [$out, $err];
}

function readStream($stream): string
{
    rewind($stream);

    return stream_get_contents($stream) ?: '';
}

it('prefixes a complete single-chunk line', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, "hello world\n");

    expect(readStream($out))->toBe("[sqs] hello world\n");
    expect(readStream($err))->toBe('');
});

it('prefixes every line in a multi-line chunk', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('redis', Process::OUT, "alpha\nbeta\ngamma\n");

    expect(readStream($out))->toBe("[redis] alpha\n[redis] beta\n[redis] gamma\n");
});

it('reassembles a line split across two chunks', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, 'hello ');
    expect(readStream($out))->toBe('');

    $p->append('sqs', Process::OUT, "world\n");
    expect(readStream($out))->toBe("[sqs] hello world\n");
});

it('flushes a trailing partial line on flush()', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, 'no-trailing-newline');
    expect(readStream($out))->toBe('');

    $p->flush('sqs');
    expect(readStream($out))->toBe("[sqs] no-trailing-newline\n");
});

it('emits no flush noise for a connection that wrote nothing', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->flush('idle');

    expect(readStream($out))->toBe('');
    expect(readStream($err))->toBe('');
});

it('keeps stdout and stderr buffers independent per connection', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, "stdout-line\n");
    $p->append('sqs', Process::ERR, "stderr-line\n");

    expect(readStream($out))->toBe("[sqs] stdout-line\n");
    expect(readStream($err))->toBe("[sqs] stderr-line\n");
});

it('interleaves output from multiple connections without cross-contamination', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, 'a-');
    $p->append('redis', Process::OUT, 'b-');
    $p->append('sqs', Process::OUT, "alpha\n");
    $p->append('redis', Process::OUT, "beta\n");

    expect(readStream($out))->toBe("[sqs] a-alpha\n[redis] b-beta\n");
});

it('drops a connection from the buffer table after flush', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, 'tail');
    $p->flush('sqs');
    $p->flush('sqs');

    expect(readStream($out))->toBe("[sqs] tail\n");
});

it('treats an empty chunk as a no-op', function (): void {
    [$out, $err] = makePrefixerStreams();
    $p = new WorkerOutputPrefixer($out, $err);

    $p->append('sqs', Process::OUT, '');

    expect(readStream($out))->toBe('');
});
