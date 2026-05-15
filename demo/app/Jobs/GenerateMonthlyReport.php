<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Slow demo job — pretends to roll up a month of usage data for a tenant.
 * Sleeps long enough to stay parked in the In-flight inspector for the
 * duration of a screencast or a curious click, so the new pending-modal
 * "Running for" state hero has something to count against.
 */
final class GenerateMonthlyReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 30];

    public function __construct(
        public int $tenantId,
        public string $month,
    ) {}

    public function handle(): void
    {
        Log::info('demo: aggregating monthly report', [
            'tenant_id' => $this->tenantId,
            'month' => $this->month,
        ]);
        // ~6–12 seconds of "work" — long enough to click into the modal
        // on the in-flight tab and see the running-for ticker move.
        usleep(random_int(6_000_000, 12_000_000));
        Log::info('demo: report generated', [
            'tenant_id' => $this->tenantId,
            'month' => $this->month,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['report', 'monthly', 'tenant:' . $this->tenantId];
    }
}
