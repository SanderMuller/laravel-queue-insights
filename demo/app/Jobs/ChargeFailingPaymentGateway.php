<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Deliberately-failing demo job — exists so the failed-modal has
 * something to render: a real exception + stack trace, parent lineage
 * via `Context`, and a `payment_id` riding along inside the
 * `illuminate:log:context` entry so the nested-data renderer's
 * Sentry-style parse-on-expand has something to show off.
 *
 * Single `tries = 1` so retries don't muddy the demo by re-running
 * indefinitely.
 */
final class ChargeFailingPaymentGateway implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $paymentId,
        public int $amountCents,
        public string $currency,
    ) {}

    public function handle(): void
    {
        Log::info('demo: charging payment gateway', [
            'payment_id' => $this->paymentId,
            'amount_cents' => $this->amountCents,
        ]);
        usleep(random_int(200_000, 800_000));
        throw new RuntimeException(sprintf(
            'Payment gateway returned 502 for payment %s (%d %s) — upstream timeout after 3 retries',
            $this->paymentId,
            $this->amountCents,
            $this->currency,
        ));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['payment', 'failing', 'demo'];
    }
}
