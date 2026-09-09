<?php declare(strict_types=1);

use Symfony\Component\Process\Process;

it('boots with the dashboard enabled while livewire has no registered provider', function (): void {
    $probe = dirname(__DIR__) . '/Fixtures/DashboardBootProbe.php';
    if (! is_file($probe)) {
        $this->markTestSkipped('DashboardBootProbe.php fixture missing');
    }

    $process = new Process([PHP_BINARY, $probe], dirname(__DIR__, 2));
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('booted');
});
