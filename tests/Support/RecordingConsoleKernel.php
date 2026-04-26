<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests\Support;

use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Foundation\Bus\PendingDispatch;
use stdClass;

/**
 * Test-only Console Kernel that records every `call()` invocation in a static
 * array. Used by tests that need to assert `Artisan::call('queue:retry', …)`
 * fired with the right argument shape — Mockery can't mock Testbench's
 * `final` Console\Kernel via the Artisan facade, so we swap the contract
 * binding to this recorder for the duration of the test.
 *
 * Method signatures match the parent interface exactly (untyped `$input` /
 * `$output` / `$status`) — adding stricter types here breaks PHPStan's
 * contravariance check against `Kernel::handle/terminate`.
 */
final class RecordingConsoleKernel implements KernelContract
{
    /** @var list<array{command: string, params: array<array-key, mixed>}> */
    public static array $calls = [];

    /**
     * Exit code returned by `call()`. Tests can override to simulate
     * `queue:retry` rejecting a row (already-retried, missing, driver
     * error). Default 0 (success).
     */
    public static int $nextExitCode = 0;

    public static function reset(): void
    {
        self::$calls = [];
        self::$nextExitCode = 0;
    }

    public function bootstrap(): void
    {
        // No bootstrapping needed — tests don't drive a real console run.
    }

    public function handle(mixed $input, mixed $output = null): int
    {
        return 0;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function call(mixed $command, array $parameters = [], mixed $outputBuffer = null): int
    {
        self::$calls[] = ['command' => is_string($command) ? $command : '', 'params' => $parameters];

        return self::$nextExitCode;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function queue(mixed $command, array $parameters = []): PendingDispatch
    {
        // PendingDispatch's constructor needs a job. Tests do not exercise
        // queue(), so return a no-op stub. This satisfies the contract type.
        return new PendingDispatch(new stdClass());
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return [];
    }

    public function output(): string
    {
        return '';
    }

    public function terminate(mixed $input, mixed $status): void
    {
        // No-op.
    }

    public function setArtisan(?Application $artisan): void
    {
        // No-op.
    }
}
