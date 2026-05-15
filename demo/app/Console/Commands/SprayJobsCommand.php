<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ChargeFailingPaymentGateway;
use App\Jobs\GenerateMonthlyReport;
use App\Jobs\RebuildSearchIndex;
use App\Jobs\SendInvoiceEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Populates the queue-insights dashboard with a realistic mix of work
 * so the demo's modals (completed / failed / pending / in-flight /
 * batch / chain) have actual rows to drill into.
 *
 * Every dispatch sets a fresh `Illuminate\Support\Facades\Context` so
 * the serialized job payload carries an `illuminate:log:context` entry
 * — that's the exact value the pending-modal + details-modal nested-
 * data renderer now decodes (Sentry-style, via `ValueParser`) when
 * `pending.capture.payloads = full` is set. Without per-job Context,
 * the new ValueParser path has nothing interesting to expand on.
 */
final class SprayJobsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:spray-jobs
        {--count=8 : How many of each kind to dispatch}
        {--no-batch : Skip the batched dispatch}
        {--no-fail : Skip the deliberately-failing payment job}';

    /**
     * @var string
     */
    protected $description = 'Dispatch a realistic mix of demo jobs (with Laravel Context) so the dashboard has data to show.';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $requestId = (string) Str::ulid();

        $this->info("→ Spraying ~{$count}× of each job kind. Request id: {$requestId}");

        // Top-level Context — represents the "spray run" itself. Each
        // dispatch overlays per-job keys (user_id / tenant_id /
        // payment_id) on top so the modal shows distinct context
        // per row.
        Context::add('request_id', $requestId);
        Context::add('dispatcher', 'demo:spray-jobs');
        Context::add('environment', app()->environment());

        $this->sprayInvoices($count);
        $this->sprayReports(max(1, intdiv($count, 2)));
        $this->sprayDelayedIndexRebuilds(max(1, intdiv($count, 2)));

        if (! $this->option('no-batch')) {
            $this->sprayBatchedInvoices($count);
        }

        if (! $this->option('no-fail')) {
            $this->sprayFailingCharges(max(2, intdiv($count, 3)));
        }

        $this->newLine();
        $this->info('✓ Spray complete. Open the dashboard at /queue-insights to see them flow through.');
        $this->line('  Tip: enable QUEUE_INSIGHTS_PENDING_CAPTURE_PAYLOADS=full to see Context decoded on the pending/in-flight modal.');

        return self::SUCCESS;
    }

    private function sprayInvoices(int $count): void
    {
        $this->line('  · {' . $count . '} SendInvoiceEmail (immediate)');
        for ($i = 0; $i < $count; $i++) {
            $userId = random_int(1_000, 9_999);
            Context::add('user_id', $userId);
            Context::add('tenant_id', random_int(10, 30));

            SendInvoiceEmail::dispatch(
                invoiceId: random_int(100_000, 999_999),
                userId: $userId,
                emailTo: 'user' . $userId . '@demo.test',
            );

            Context::forget(['user_id', 'tenant_id']);
        }
    }

    private function sprayReports(int $count): void
    {
        $this->line('  · {' . $count . '} GenerateMonthlyReport (slow, hangs around the in-flight list)');
        $months = ['2026-03', '2026-04', '2026-05'];
        for ($i = 0; $i < $count; $i++) {
            $tenantId = random_int(10, 30);
            Context::add('tenant_id', $tenantId);
            Context::add('report_kind', 'usage_summary');

            GenerateMonthlyReport::dispatch(
                tenantId: $tenantId,
                month: $months[array_rand($months)],
            );

            Context::forget(['tenant_id', 'report_kind']);
        }
    }

    private function sprayDelayedIndexRebuilds(int $count): void
    {
        $this->line('  · {' . $count . '} RebuildSearchIndex (delayed — populates the Delayed sub-table)');
        $indexes = ['products', 'users', 'orders', 'audit_log'];
        for ($i = 0; $i < $count; $i++) {
            $index = $indexes[array_rand($indexes)];
            Context::add('index_name', $index);
            Context::add('reason', 'nightly_rebuild');

            RebuildSearchIndex::dispatch(
                indexName: $index,
                shardKeys: array_map(static fn (int $n): string => 'shard-' . $n, range(0, random_int(2, 6))),
            )->delay(now()->addSeconds(random_int(30, 600)));

            Context::forget(['index_name', 'reason']);
        }
    }

    private function sprayBatchedInvoices(int $count): void
    {
        $this->line('  · 1× Bus::batch with ' . $count . ' SendInvoiceEmail items (lights up the Batches tab)');
        Context::add('batch_purpose', 'monthly_invoice_run');
        Context::add('tenant_id', random_int(10, 30));

        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            $userId = random_int(1_000, 9_999);
            $jobs[] = new SendInvoiceEmail(
                invoiceId: random_int(100_000, 999_999),
                userId: $userId,
                emailTo: 'user' . $userId . '@demo.test',
            );
        }

        Bus::batch($jobs)
            ->name('Monthly invoice run ' . now()->format('Y-m-d H:i'))
            ->allowFailures()
            ->then(static function (): void {
                // No-op closure — only here so the batch records a
                // "finished" event on the batches repository. (Batch
                // arg dropped — unused locally; the callback signature
                // permits fewer params than the dispatcher passes.)
            })
            ->dispatch();

        Context::forget(['batch_purpose', 'tenant_id']);
    }

    private function sprayFailingCharges(int $count): void
    {
        $this->line('  · {' . $count . '} ChargeFailingPaymentGateway (throws, populates the Failed list)');
        $currencies = ['EUR', 'USD', 'GBP'];
        for ($i = 0; $i < $count; $i++) {
            $paymentId = 'pay_' . Str::lower(Str::random(14));
            Context::add('payment_id', $paymentId);
            Context::add('user_id', random_int(1_000, 9_999));
            Context::add('attempt_origin', 'demo_spray');

            try {
                ChargeFailingPaymentGateway::dispatch(
                    paymentId: $paymentId,
                    amountCents: random_int(500, 50_000),
                    currency: $currencies[array_rand($currencies)],
                );
            } catch (Throwable $e) {
                // Dispatch itself shouldn't throw — but if the queue
                // backend is misconfigured we still want the spray
                // command to continue past the failure rather than
                // half-populating the dashboard.
                $this->warn('  ! dispatch failed: ' . $e->getMessage());
            }

            Context::forget(['payment_id', 'user_id', 'attempt_origin']);
        }
    }
}
