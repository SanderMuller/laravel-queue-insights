<?php declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Phase 4 — dashboard surfacing of the job-initiator fields (origin +
 * call_site) in the details / pending / failed modals.
 *
 * The three modals are blade components; component views are not
 * addressable via `view()` directly, so `Blade::render` compiles the
 * component invocation in-process (same approach as `renderFailedModal`
 * in ChainLineageSurfaceTest). Each modal reads `origin` / `call_site`
 * straight off its row array — DashboardData / RowEnricher /
 * PendingJobsReader put them there — so feeding the props directly
 * exercises the exact blade scope.
 */

/**
 * @param  array<string, mixed>  $payload
 */
function renderDetailsModal(array $payload): string
{
    return Blade::render(
        '<x-queue-insights::details-modal :payload="$payload" />',
        ['payload' => $payload],
    );
}

/**
 * @param  array<string, mixed>  $pending
 */
function renderPendingModal(array $pending): string
{
    return Blade::render(
        '<x-queue-insights::pending-modal :pending="$pending" />',
        ['pending' => $pending],
    );
}

/**
 * @param  array<string, mixed>  $failed
 */
function renderInitiatorFailedModal(array $failed): string
{
    return Blade::render(
        '<x-queue-insights::failed-modal :failed="$failed" :canRetry="false" expandedBatchId="" />',
        ['failed' => $failed],
    );
}

// --- details-modal (completed jobs) -------------------------------------

it('details-modal renders the Origin and Dispatched from rows when present', function (): void {
    $html = renderDetailsModal([
        '_id' => '1700000000-0',
        'class' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'origin' => 'http:checkout.store',
        'call_site' => 'app/Services/Billing.php:88',
    ]);

    expect($html)
        ->toContain('Origin')
        ->toContain('http:checkout.store')
        ->toContain('Dispatched from')
        ->toContain('app/Services/Billing.php:88');
});

it('details-modal omits both initiator rows when origin and call_site are absent', function (): void {
    $html = renderDetailsModal([
        '_id' => '1700000000-0',
        'class' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    expect($html)
        ->not->toContain('Origin')
        ->not->toContain('Dispatched from');
});

it('details-modal shows Origin alone when call_site is missing', function (): void {
    $html = renderDetailsModal([
        '_id' => '1700000000-0',
        'class' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'origin' => 'artisan:app:sync-orders',
    ]);

    expect($html)
        ->toContain('Origin')
        ->toContain('artisan:app:sync-orders')
        ->not->toContain('Dispatched from');
});

// --- pending-modal (pending / in-flight jobs) ---------------------------

it('pending-modal renders the Origin and Dispatched from rows when present', function (): void {
    $html = renderPendingModal([
        'uuid' => 'pending-uuid-1',
        'class' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'queued_at' => 1700000000,
        'available_at' => 1700000000,
        'origin' => 'schedule:backup-db',
        'call_site' => 'app/Console/Kernel.php:31',
    ]);

    expect($html)
        ->toContain('Origin')
        ->toContain('schedule:backup-db')
        ->toContain('Dispatched from')
        ->toContain('app/Console/Kernel.php:31');
});

it('pending-modal omits both initiator rows when origin and call_site are absent', function (): void {
    $html = renderPendingModal([
        'uuid' => 'pending-uuid-1',
        'class' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'queued_at' => 1700000000,
        'available_at' => 1700000000,
    ]);

    expect($html)
        ->not->toContain('Origin')
        ->not->toContain('Dispatched from');
});

it('pending-modal shows Dispatched from alone when origin is missing', function (): void {
    $html = renderPendingModal([
        'uuid' => 'pending-uuid-1',
        'class' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'queued_at' => 1700000000,
        'available_at' => 1700000000,
        'call_site' => 'app/Jobs/Dispatcher.php:12',
    ]);

    expect($html)
        ->toContain('Dispatched from')
        ->toContain('app/Jobs/Dispatcher.php:12')
        ->not->toContain('Origin');
});

// --- failed-modal (failed jobs) -----------------------------------------

it('failed-modal renders the Origin and Dispatched from rows when present', function (): void {
    $html = renderInitiatorFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'origin' => 'http:checkout.store',
        'call_site' => 'app/Services/Billing.php:88',
    ]);

    expect($html)
        ->toContain('Origin')
        ->toContain('http:checkout.store')
        ->toContain('Dispatched from')
        ->toContain('app/Services/Billing.php:88');
});

it('failed-modal omits both initiator rows when origin and call_site are absent', function (): void {
    $html = renderInitiatorFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'origin' => null,
        'call_site' => null,
    ]);

    expect($html)
        ->not->toContain('Origin')
        ->not->toContain('Dispatched from');
});

// --- failed-modal markdown export ---------------------------------------

it('failed-modal markdown export includes Origin and Dispatched from lines when present', function (): void {
    $html = renderInitiatorFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'origin' => 'http:checkout.store',
        'call_site' => 'app/Services/Billing.php:88',
    ]);

    expect($html)
        ->toContain('- **Origin:** http:checkout.store')
        ->toContain('- **Dispatched from:** `app/Services/Billing.php:88`');
});

it('failed-modal markdown export omits Origin and Dispatched from lines when absent', function (): void {
    $html = renderInitiatorFailedModal([
        'id' => 7,
        'uuid' => 'failed-uuid',
        'connection' => 'redis',
        'queue' => 'default',
        'failed_at' => '2026-04-29T00:00:00+00:00',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]),
        'exception' => 'RuntimeException: boom',
        'origin' => null,
        'call_site' => null,
    ]);

    expect($html)
        ->not->toContain('**Origin:**')
        ->not->toContain('**Dispatched from:**');
});
