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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use SanderMuller\QueueInsights\Support\Config as QiConfig;
use SanderMuller\QueueInsights\Support\KeyPrefix;
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
        {--no-fail : Skip the deliberately-failing payment job}
        {--simulate-in-flight=3 : Mark N freshly-dispatched uuids as in-flight in Redis so the In-flight tab is populated without a running worker. Set to 0 to skip.}
        {--synthesize-failed=3 : Insert N rows directly into the failed_jobs table (with realistic payload + Context) so the Failed tab is populated without a running worker. Set to 0 to skip.}';

    /**
     * @var string
     */
    protected $description = 'Dispatch a realistic mix of demo jobs (with Laravel Context) so the dashboard has data to show.';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $simulateInFlight = max(0, (int) $this->option('simulate-in-flight'));
        $synthesizeFailed = max(0, (int) $this->option('synthesize-failed'));
        $requestId = (string) Str::ulid();

        $this->info("→ Spraying ~{$count}× of each job kind. Request id: {$requestId}");

        // Top-level Context wrapped in try/finally so leaked keys can't
        // bleed into subsequent CLI work running in the same PHP process
        // (matters when the seeder calls this via `Artisan::call()` —
        // the seeder process continues after handle() returns).
        $topLevelKeys = ['request_id', 'dispatcher', 'environment'];
        Context::add('request_id', $requestId);
        Context::add('dispatcher', 'demo:spray-jobs');
        Context::add('environment', app()->environment());

        try {
            $this->sprayInvoices($count);
            $this->sprayReports(max(1, intdiv($count, 2)));
            $this->sprayDelayedIndexRebuilds(max(1, intdiv($count, 2)));

            if (! $this->option('no-batch')) {
                $this->sprayBatchedInvoices($count);
            }

            if (! $this->option('no-fail')) {
                $this->sprayFailingCharges(max(2, intdiv($count, 3)));
            }

            if ($simulateInFlight > 0) {
                $this->simulateInFlight($simulateInFlight);
            }

            if ($synthesizeFailed > 0) {
                $this->synthesizeFailed($synthesizeFailed);
            }
        } finally {
            Context::forget($topLevelKeys);
        }

        $this->newLine();
        $this->info('✓ Spray complete. Open the dashboard at /queue-insights to see them flow through.');
        $this->line('  Tip: enable QUEUE_INSIGHTS_PENDING_CAPTURE_PAYLOADS=full to see Context decoded on the pending/in-flight modal.');

        return self::SUCCESS;
    }

    /**
     * Run `$body` inside a Context overlay (`$keys => values`) with
     * try/finally cleanup so a thrown dispatch never leaks keys into
     * the next iteration. Mirrors the pattern Laravel uses internally
     * for queue-context middleware.
     *
     * @param  array<string, mixed>  $keys
     */
    private function withContext(array $keys, callable $body): void
    {
        foreach ($keys as $name => $value) {
            Context::add($name, $value);
        }

        try {
            $body();
        } finally {
            Context::forget(array_keys($keys));
        }
    }

    private function sprayInvoices(int $count): void
    {
        $this->line('  · ' . $count . ' SendInvoiceEmail (immediate)');
        for ($i = 0; $i < $count; $i++) {
            $userId = random_int(1_000, 9_999);
            $this->withContext([
                'user_id' => $userId,
                'tenant_id' => random_int(10, 30),
            ], static function () use ($userId): void {
                SendInvoiceEmail::dispatch(
                    invoiceId: random_int(100_000, 999_999),
                    userId: $userId,
                    emailTo: 'user' . $userId . '@demo.test',
                );
            });
        }
    }

    private function sprayReports(int $count): void
    {
        $this->line('  · ' . $count . ' GenerateMonthlyReport (slow, hangs around the in-flight list)');
        $months = ['2026-03', '2026-04', '2026-05'];
        for ($i = 0; $i < $count; $i++) {
            $tenantId = random_int(10, 30);
            $this->withContext([
                'tenant_id' => $tenantId,
                'report_kind' => 'usage_summary',
            ], static function () use ($tenantId, $months): void {
                GenerateMonthlyReport::dispatch(
                    tenantId: $tenantId,
                    month: $months[array_rand($months)],
                );
            });
        }
    }

    private function sprayDelayedIndexRebuilds(int $count): void
    {
        $this->line('  · ' . $count . ' RebuildSearchIndex (delayed — populates the Delayed sub-table)');
        $indexes = ['products', 'users', 'orders', 'audit_log'];
        for ($i = 0; $i < $count; $i++) {
            $index = $indexes[array_rand($indexes)];
            $this->withContext([
                'index_name' => $index,
                'reason' => 'nightly_rebuild',
            ], static function () use ($index): void {
                RebuildSearchIndex::dispatch(
                    indexName: $index,
                    shardKeys: array_map(static fn (int $n): string => 'shard-' . $n, range(0, random_int(2, 6))),
                )->delay(now()->addSeconds(random_int(30, 600)));
            });
        }
    }

    private function sprayBatchedInvoices(int $count): void
    {
        $this->line('  · 1 Bus::batch with ' . $count . ' SendInvoiceEmail items (lights up the Batches tab)');

        // The batch's `->dispatch()` captures Context once at call time and
        // pins it onto every queued item — Bus::batch doesn't re-snapshot
        // per-job. So the per-user `user_id` overlay we'd want inside the
        // loop is lost. We bake the user_id into the job's own constructor
        // args (already there) and only set batch-scope Context here.
        $this->withContext([
            'batch_purpose' => 'monthly_invoice_run',
            'tenant_id' => random_int(10, 30),
        ], static function () use ($count): void {
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
                    // "finished" event on the batches repository.
                })
                ->dispatch();
        });
    }

    private function sprayFailingCharges(int $count): void
    {
        $this->line('  · ' . $count . ' ChargeFailingPaymentGateway (throws, populates the Failed list)');
        $currencies = ['EUR', 'USD', 'GBP'];
        for ($i = 0; $i < $count; $i++) {
            $paymentId = 'pay_' . Str::lower(Str::random(14));
            $this->withContext([
                'payment_id' => $paymentId,
                'user_id' => random_int(1_000, 9_999),
                'attempt_origin' => 'demo_spray',
            ], function () use ($paymentId, $currencies): void {
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
            });
        }
    }

    /**
     * Pretend a worker is mid-flight on a few of the rows we just
     * dispatched so the In-flight tab is non-empty in the demo without
     * needing `php artisan queue:work` running.
     *
     * Reads back the most recent pending uuids across all configured
     * queues, picks N at random, and stamps `state=in_flight` +
     * `started_at` onto their hashes — exactly the fields
     * `RecordJobProcessing` would write when a real worker pops the
     * payload. The pending-modal reads these fields and shows the
     * Running-for state hero against them.
     *
     * Synthetic by design — once a real worker runs in the demo the
     * dispatcher's natural flow takes over.
     */
    private function simulateInFlight(int $target): void
    {
        $this->line('  · simulating ' . $target . ' in-flight rows (no worker required)');
        $redis = Redis::connection(QiConfig::string('redis_connection', 'default'));

        $uuids = $redis->command('zrevrange', [KeyPrefix::make('pending-zset:redis:default'), 0, max(20, $target * 4)]);
        $uuids = is_array($uuids)
            ? array_values(array_filter($uuids, static fn ($v): bool => is_string($v) && $v !== ''))
            : [];

        if ($uuids === []) {
            $this->warn('  ! no pending rows to mark in-flight (check QUEUE_INSIGHTS_PENDING_ENABLED + your queue connection)');

            return;
        }

        shuffle($uuids);
        $picked = array_slice($uuids, 0, $target);
        $now = time();

        foreach ($picked as $uuid) {
            $startedAt = $now - random_int(5, 120);
            $hashKey = KeyPrefix::make('pending:' . $uuid);
            $redis->command('hset', [$hashKey, 'state', 'in_flight']);
            $redis->command('hset', [$hashKey, 'started_at', (string) $startedAt]);
            $redis->command('hset', [$hashKey, 'attempts', (string) random_int(1, 2)]);
            // Mirror RecordJobProcessing's inflight-zset write so the
            // In-flight cross-queue aggregator sees the row.
            $redis->command('zadd', [KeyPrefix::make('inflight-zset:redis:default'), $startedAt, $uuid]);
        }
    }

    /**
     * Insert N rows directly into the `failed_jobs` table so the Failed
     * tab is populated without needing `queue:work` to have actually run
     * a failing job. Builds the same payload shape Laravel's queue
     * serializer would push at JobQueued time — including a top-level
     * `illuminate:log:context` key with synthetic per-row Context — so
     * the failed-modal's `structured-payload` renderer + the nested-data
     * tree show realistic data on click.
     *
     * Synthetic by design. Once `queue:work` runs against a real
     * ChargeFailingPaymentGateway dispatch, Laravel's own failure
     * handler will land additional rows alongside these.
     */
    private function synthesizeFailed(int $count): void
    {
        $this->line('  · synthesizing ' . $count . ' failed_jobs rows (no worker required)');
        $currencies = ['EUR', 'USD', 'GBP'];

        for ($i = 0; $i < $count; $i++) {
            $paymentId = 'pay_' . Str::lower(Str::random(14));
            $userId = random_int(1_000, 9_999);
            $amountCents = random_int(500, 50_000);
            $currency = $currencies[array_rand($currencies)];
            $uuid = (string) Str::uuid();

            // Real instance → real serialized blob, so the
            // SerializedCommandReader pipeline has something genuine to
            // decode on the modal (instance properties + class name).
            $jobInstance = new ChargeFailingPaymentGateway($paymentId, $amountCents, $currency);
            $command = serialize($jobInstance);

            $payload = json_encode([
                'uuid' => $uuid,
                'displayName' => ChargeFailingPaymentGateway::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 1,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'retryUntil' => null,
                'data' => [
                    'commandName' => ChargeFailingPaymentGateway::class,
                    'command' => $command,
                ],
                // Top-level Context key — matches Laravel 11+'s
                // `ContextServiceProvider::boot` shape so the failed-modal's
                // structured-payload "Other fields" renderer picks it up
                // and shows the tree inline.
                'illuminate:log:context' => [
                    'request_id' => (string) Str::ulid(),
                    'dispatcher' => 'demo:spray-jobs (synthesized)',
                    'environment' => app()->environment(),
                    'payment_id' => $paymentId,
                    'user_id' => $userId,
                    'attempt_origin' => 'demo_spray',
                ],
                'tags' => ['payment', 'failing', 'demo'],
            ], JSON_UNESCAPED_SLASHES);

            $exception = sprintf(
                "RuntimeException: Payment gateway returned 502 for payment %s (%d %s) — upstream timeout after 3 retries in /var/www/app/Jobs/ChargeFailingPaymentGateway.php:48\n"
                . "Stack trace:\n"
                . "#0 [internal function]: App\\Jobs\\ChargeFailingPaymentGateway->handle()\n"
                . "#1 /var/www/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): call_user_func_array()\n"
                . "#2 /var/www/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::Illuminate\\Container\\{closure}()\n"
                . "#3 /var/www/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n"
                . "#4 /var/www/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(123): Illuminate\\Container\\BoundMethod::callBoundMethod()\n"
                . '#5 {main}',
                $paymentId,
                $amountCents,
                $currency,
            );

            DB::table('failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => $payload === false ? '{}' : $payload,
                'exception' => $exception,
                'failed_at' => now(),
            ]);
        }
    }
}
