<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Scheduler\CommandLabel;

it('returns an empty string for empty input', function (): void {
    expect(CommandLabel::short(''))
        ->toBeEmpty();
});

it('strips a Herd-style absolute php binary', function (): void {
    $command = "'/Users/alice/Library/Application Support/Herd/bin/php' 'artisan' 'queue-insights:snapshot'";

    expect(CommandLabel::short($command))->toBe('php artisan queue-insights:snapshot');
});

it('strips a Homebrew-style absolute php binary', function (): void {
    $command = "'/opt/homebrew/bin/php' 'artisan' 'schedule:run'";

    expect(CommandLabel::short($command))->toBe('php artisan schedule:run');
});

it('strips a Linux-style absolute php binary', function (): void {
    $command = "'/usr/local/bin/php' 'artisan' 'queue:work'";

    expect(CommandLabel::short($command))->toBe('php artisan queue:work');
});

it('preserves a versioned interpreter suffix', function (): void {
    $command = "'/usr/bin/php8.2' 'artisan' 'foo'";

    expect(CommandLabel::short($command))->toBe('php8.2 artisan foo');
});

it('strips a Windows-style absolute php.exe', function (): void {
    $command = "'C:\\php\\php.exe' 'artisan' 'foo'";

    expect(CommandLabel::short($command))->toBe('php artisan foo');
});

it('passes through an already-short command', function (): void {
    expect(CommandLabel::short("'php' 'artisan' 'foo:bar'"))->toBe('php artisan foo:bar');
});

it('preserves quoted args containing spaces', function (): void {
    $command = "'/usr/bin/php' 'artisan' 'foo' 'arg with spaces'";

    expect(CommandLabel::short($command))->toBe("php artisan foo 'arg with spaces'");
});

it('returns input unchanged when no recognisable php prefix matches', function (): void {
    expect(CommandLabel::short('echo hello'))->toBe('echo hello');
});

it('handles a raw exec command without quotes', function (): void {
    $command = '/usr/local/bin/php artisan ping';

    expect(CommandLabel::short($command))->toBe('php artisan ping');
});
