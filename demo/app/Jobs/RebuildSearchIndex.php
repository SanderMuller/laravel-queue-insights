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
 * Delayed-dispatch demo job — the spray command pushes this with
 * `->delay(now()->addMinutes(...))` so it lands on the Delayed sub-table
 * with a non-trivial available_at horizon. The pending-modal renders
 * the indigo "Scheduled at" state hero for this one.
 */
final class RebuildSearchIndex implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $shardKeys
     */
    public function __construct(
        public string $indexName,
        public array $shardKeys,
    ) {}

    public function handle(): void
    {
        Log::info('demo: rebuilding search index', [
            'index' => $this->indexName,
            'shards' => count($this->shardKeys),
        ]);
        usleep(random_int(2_000_000, 4_000_000));
        Log::info('demo: search index rebuilt', ['index' => $this->indexName]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['search', 'index:' . $this->indexName];
    }
}
