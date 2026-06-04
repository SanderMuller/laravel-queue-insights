<?php declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Surfacing of the failure-context snapshot (Context + Environment) and the
 * scheduler inner exception in the failed-job and scheduled-run modals — both
 * the visual sections and the Markdown export. Mirrors InitiatorModalSurfaceTest:
 * the modals are blade components, fed props directly via Blade::render.
 *
 * @param  array<string, mixed>  $failed
 */
function renderFailureCtxFailedModal(array $failed): string
{
    return Blade::render(
        '<x-queue-insights::failed-modal :failed="$failed" :canRetry="false" expandedBatchId="" />',
        ['failed' => $failed],
    );
}

/**
 * @param  array<string, mixed>  $run
 */
function renderFailureCtxRunModal(array $run): string
{
    return Blade::render(
        '<x-queue-insights::schedule-run-modal :run="$run" :output="null" taskLabel="demo" :isClosure="false" />',
        ['run' => $run],
    );
}

/**
 * @return array<string, mixed>
 */
function failureCtxFailedRow(mixed $failureContext): array
{
    return [
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'failure_context' => $failureContext,
    ];
}

it('failed-modal renders the Context + Environment sections and markdown when present', function (): void {
    $html = renderFailureCtxFailedModal(failureCtxFailedRow([
        'app_context' => ['user_id' => 42, 'tenant' => 'acme'],
        'environment' => ['host' => 'web-1', 'pid' => 1234, 'env' => 'production', 'release' => '2.4.0'],
    ]));

    expect($html)
        // visual sections
        ->toContain('data-section="failure-context"')
        ->toContain('data-section="failure-environment"')
        ->toContain('user_id')
        ->toContain('acme')
        ->toContain('web-1')
        // markdown export
        ->toContain('## Context')
        ->toContain('- **user_id:** 42')
        ->toContain('## Environment')
        ->toContain('- **Host:** web-1')
        ->toContain('- **Release:** 2.4.0');
});

it('failed-modal omits the Context + Environment sections when the snapshot is empty', function (): void {
    $html = renderFailureCtxFailedModal(failureCtxFailedRow([
        'app_context' => [],
        'environment' => [],
    ]));

    expect($html)
        ->not->toContain('data-section="failure-context"')
        ->not->toContain('data-section="failure-environment"')
        ->not->toContain('## Context')
        ->not->toContain('## Environment');
});

it('scheduled-run modal renders the inner exception and Context section', function (): void {
    $html = renderFailureCtxRunModal([
        'task_key' => 'demo',
        'run_id' => 'run-1',
        'started_at_ms' => null,
        'finished_at_ms' => null,
        'runtime_ms' => null,
        'exit_code' => 1,
        'status' => 'failed',
        'skip_reason' => null,
        'host_id' => 'host-1',
        'is_background' => false,
        'recovered_from_hung' => false,
        'exception' => [
            'class' => 'RuntimeException',
            'message' => 'wrapper',
            'inner_class' => 'LogicException',
            'inner_message' => 'root cause',
            'file' => 'app/x.php',
            'line' => 10,
            'trace_tail' => '',
        ],
        'app_context' => ['user_id' => 9],
        'environment' => ['host' => 'host-1', 'pid' => 5, 'env' => 'production', 'release' => null],
        'has_output' => false,
        'correlated_jobs' => [],
    ]);

    expect($html)
        ->toContain('Caused by:')
        ->toContain('LogicException')
        ->toContain('root cause')
        ->toContain('data-section="failure-context"')
        ->toContain('user_id')
        // markdown export
        ->toContain('Caused by: LogicException: root cause')
        ->toContain('## Context')
        ->toContain('- **Host:** host-1');
});
