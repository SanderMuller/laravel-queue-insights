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
 * Happy-path demo job. Pretends to render + send an invoice email so the
 * dashboard can show a Completed row with realistic per-job context.
 *
 * The interesting bit for the dashboard isn't the work — it's that
 * `Illuminate\Support\Facades\Context` keys set by the dispatching
 * command (request id, tenant id, etc.) ride along into the serialized
 * job payload as `illuminate:log:context`. queue-insights' nested-data
 * renderer + `ValueParser` decode that key on the modal so operators
 * see the parsed structure instead of an escaped serialized blob.
 */
final class SendInvoiceEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $invoiceId,
        public int $userId,
        public string $emailTo,
    ) {}

    public function handle(): void
    {
        // Pretend to render the PDF + push to the mail driver. The sleep
        // keeps the row visible on the in-flight inspector long enough
        // to click into the modal during a demo screencast.
        Log::info('demo: rendering invoice', [
            'invoice_id' => $this->invoiceId,
            'user_id' => $this->userId,
        ]);
        usleep(random_int(800_000, 2_500_000));
        Log::info('demo: invoice email sent', [
            'invoice_id' => $this->invoiceId,
            'to' => $this->emailTo,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['invoice', 'email', 'user:' . $this->userId];
    }
}
