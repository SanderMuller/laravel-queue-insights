<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Symfony\Component\Process\Process;

/**
 * Per-(connection, stream) carry buffer that prefixes every complete
 * line of child output with `[{connection}] ` before writing to the
 * parent's stdout / stderr.
 *
 * Symfony's `Process::start($cb)` invokes the callback with whatever
 * was pulled off the pipe — chunks may split a line mid-byte. A naive
 * `explode("\n", $chunk)` would mis-prefix the trailing fragment.
 * The carry-buffer implementation accumulates partials per (connection,
 * stream) pair, splits off complete lines on `\n`, and retains the
 * tail. `flush()` emits any non-empty residual on child exit so an
 * unterminated tail is not lost.
 */
final class WorkerOutputPrefixer
{
    /**
     * @var array<string, array{out: string, err: string}>
     */
    private array $buffers = [];

    /**
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function __construct(
        private readonly mixed $stdout,
        private readonly mixed $stderr,
    ) {}

    public function append(string $connection, string $type, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->buffers[$connection] ??= ['out' => '', 'err' => ''];
        $key = $type === Process::ERR ? 'err' : 'out';

        $merged = $this->buffers[$connection][$key] . $chunk;
        $lastNewline = strrpos($merged, "\n");

        if ($lastNewline === false) {
            $this->buffers[$connection][$key] = $merged;

            return;
        }

        $emit = substr($merged, 0, $lastNewline);
        $tail = substr($merged, $lastNewline + 1);
        $stream = $key === 'err' ? $this->stderr : $this->stdout;

        foreach (explode("\n", $emit) as $line) {
            fwrite($stream, '[' . $connection . '] ' . $line . "\n");
        }

        $this->buffers[$connection][$key] = $tail;
    }

    public function flush(string $connection): void
    {
        if (! isset($this->buffers[$connection])) {
            return;
        }

        foreach (['out' => $this->stdout, 'err' => $this->stderr] as $key => $stream) {
            $tail = $this->buffers[$connection][$key];
            if ($tail !== '') {
                fwrite($stream, '[' . $connection . '] ' . $tail . "\n");
            }
        }

        unset($this->buffers[$connection]);
    }
}
