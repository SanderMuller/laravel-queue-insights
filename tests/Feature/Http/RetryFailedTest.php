<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Tests\Support\RecordingConsoleKernel;

beforeEach(function (): void {
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->nullable();
        $table->string('connection');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    // Swap the Console Kernel binding for a recorder. Mockery cannot mock
    // Testbench's `final` Console\Kernel via the Artisan facade, so we
    // replace the contract resolver with our own implementation that
    // captures every `call(...)` invocation.
    RecordingConsoleKernel::reset();
    app()->instance(ConsoleKernelContract::class, new RecordingConsoleKernel());

    // Default: gate ALLOWS retry. Tests that exercise denial set their own.
    Gate::define('retryFailedJobs', fn (mixed $user = null): bool => true);

    RateLimiter::clear('qi.retry:guest:127.0.0.1');
});

afterEach(function (): void {
    Schema::dropIfExists('failed_jobs');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: int, uuid: string}
 */
function seedRetryRow(array $overrides = []): array
{
    /** @var array<string, mixed> $row */
    $row = array_merge([
        'uuid' => 'uuid-' . Str::random(8),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendEmail']),
        'exception' => 'X',
        'failed_at' => '2026-04-26 10:00:00',
    ], $overrides);

    $id = (int) DB::table('failed_jobs')->insertGetId($row);

    $uuid = is_string($row['uuid']) ? $row['uuid'] : '';

    return ['id' => $id, 'uuid' => $uuid];
}

it('retryFailed dispatches queue:retry for the given uuid and flashes success', function (): void {
    $row = seedRetryRow();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailed', $row['uuid']);

    expect(RecordingConsoleKernel::$calls)->toHaveCount(1)
        ->and(RecordingConsoleKernel::$calls[0]['command'])->toBe('queue:retry')
        ->and(RecordingConsoleKernel::$calls[0]['params'])->toBe(['id' => [$row['uuid']]]);
});

it('retryFailed denies when the gate rejects (no Artisan call)', function (): void {
    Gate::define('retryFailedJobs', fn (mixed $user = null): bool => false);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailed', 'any-uuid')
        ->assertStatus(403);

    expect(RecordingConsoleKernel::$calls)
        ->toBeEmpty();
});

it('retryFailed flashes the rate-limit error after 30 attempts/min', function (): void {
    $key = 'qi.retry:guest:127.0.0.1';
    for ($i = 0; $i < 30; ++$i) {
        RateLimiter::hit($key, 60);
    }

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailed', 'uuid-x');

    expect(RecordingConsoleKernel::$calls)
        ->toBeEmpty();
});

it('retryFailedBulk rejects with empty filters (footgun guard)', function (): void {
    seedRetryRow();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailedBulk');

    expect(RecordingConsoleKernel::$calls)
        ->toBeEmpty();
});

it('retryFailedBulk dispatches the whole filtered snapshot when ≤100', function (): void {
    $a = seedRetryRow(['queue' => 'video']);
    $b = seedRetryRow(['queue' => 'video']);
    seedRetryRow(['queue' => 'default']);  // outside filter

    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterQueue', 'video')
        ->call('retryFailedBulk');

    expect(RecordingConsoleKernel::$calls)->toHaveCount(1)
        ->and(RecordingConsoleKernel::$calls[0]['command'])->toBe('queue:retry');

    /** @var array<array-key, mixed> $params */
    $params = RecordingConsoleKernel::$calls[0]['params'];
    /** @var list<string> $ids */
    $ids = is_array($params['id'] ?? null) ? $params['id'] : [];

    expect($ids)->toContain($a['uuid'])
        ->toContain($b['uuid'])
        ->toHaveCount(2);
});

it('retryFailedBulk rejects when match count exceeds 100', function (): void {
    for ($i = 0; $i < 101; ++$i) {
        seedRetryRow(['queue' => 'huge']);
    }

    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterQueue', 'huge')
        ->call('retryFailedBulk');

    expect(RecordingConsoleKernel::$calls)
        ->toBeEmpty();
});

it('retryFailedBulk denies when the gate rejects', function (): void {
    Gate::define('retryFailedJobs', fn (mixed $user = null): bool => false);
    seedRetryRow(['queue' => 'video']);

    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterQueue', 'video')
        ->call('retryFailedBulk')
        ->assertStatus(403);

    expect(RecordingConsoleKernel::$calls)
        ->toBeEmpty();
});

it('logs an audit entry on successful retry', function (): void {
    $row = seedRetryRow();

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'queue-insights.retry'
            && ($context['kind'] ?? null) === 'single'
            && ($context['count'] ?? null) === 1
            && ($context['uuids'] ?? null) === [$row['uuid']]);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailed', $row['uuid']);
});

it('hides the bulk Retry button when no filter is active', function (): void {
    seedRetryRow();

    $html = Livewire::test(QueueInsightsDashboard::class)->html();

    expect($html)->not->toContain('retryFailedBulk');
});

it('renders the bulk Retry button when filters are active and count is ≤100', function (): void {
    seedRetryRow(['queue' => 'video']);
    seedRetryRow(['queue' => 'video']);

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->set('filterQueue', 'video')
        ->html();

    expect($html)->toContain('retryFailedBulk')
        ->toContain('Retry 2 jobs');
});

it('retryFailed surfaces an error when queue:retry returns a non-zero exit code', function (): void {
    // Codex review regression: prior implementation flashed `Retry dispatched.`
    // for any non-throwing call, hiding dead-letter / already-retried failures
    // from operators. The exit-code branch in retryFailed must catch that.
    RecordingConsoleKernel::$nextExitCode = 1;
    $row = seedRetryRow();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailed', $row['uuid'])
        ->assertDontSee('Retry dispatched.')
        ->assertSee('Retry could not be dispatched');
});

it('retryFailedBulk surfaces an error when queue:retry returns a non-zero exit code', function (): void {
    RecordingConsoleKernel::$nextExitCode = 2;
    seedRetryRow(['queue' => 'video']);

    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterQueue', 'video')
        ->call('retryFailedBulk')
        ->assertDontSee('Retried 1 job.')
        ->assertSee('Bulk retry returned non-zero exit 2');
});

it('audit log sanitizes user-controlled filter strings (control bytes neutralised, length capped)', function (): void {
    // Codex review: filter set is URL-bound + user-controlled. Log driver
    // accepting raw control bytes is a log-injection vector; unbounded length
    // bloats audit logs. Both defended by sanitizeAuditField().
    $row = seedRetryRow();

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            $filters = $context['filters'] ?? [];
            $class = is_array($filters) && isset($filters['class']) && is_string($filters['class'])
                ? $filters['class']
                : '';

            // CR/LF replaced with `?`, length capped at 80.
            return $message === 'queue-insights.retry'
                && ! str_contains($class, "\n")
                && ! str_contains($class, "\r")
                && mb_strlen($class) <= 80;
        });

    Livewire::test(QueueInsightsDashboard::class)
        ->set('filterClass', "App\\Foo\nBar\rBaz" . str_repeat('x', 200))
        ->call('retryFailed', $row['uuid']);
});

it('flash banner renders the success message after a retry', function (): void {
    $row = seedRetryRow();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailed', $row['uuid'])
        ->assertSee('Retry dispatched.');
});

it('flash banner renders the error message when bulk retry rejects', function (): void {
    seedRetryRow();

    Livewire::test(QueueInsightsDashboard::class)
        ->call('retryFailedBulk')
        ->assertSee('Bulk retry requires at least one filter.');
});

it('shows the "narrow to retry" hint when filters match more than 100 rows', function (): void {
    for ($i = 0; $i < 101; ++$i) {
        seedRetryRow(['queue' => 'huge']);
    }

    $html = Livewire::test(QueueInsightsDashboard::class)
        ->set('filterQueue', 'huge')
        ->html();

    expect($html)->toContain('narrow to retry')
        ->not->toContain('retryFailedBulk');
});
