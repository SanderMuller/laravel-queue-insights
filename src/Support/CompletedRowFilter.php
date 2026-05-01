<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Post-fetch filter for `Recent completed` rows.
 *
 * Class is pre-filtered at the storage layer via $selectedClass in
 * QueueInsights::recentCompleted(); the four fields here narrow the
 * already-fetched 50-row default cap in PHP, mirroring the filter
 * UX of the Recent failed table.
 */
final readonly class CompletedRowFilter
{
    public function __construct(
        public string $connection = '',
        public string $queue = '',
        public string $from = '',
        public string $to = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->connection === ''
            && $this->queue === ''
            && $this->from === ''
            && $this->to === '';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function apply(array $rows): array
    {
        if ($this->isEmpty()) {
            return $rows;
        }

        [$fromTs, $toTs] = $this->parseRange();

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->matches($row, $fromTs, $toTs),
        ));
    }

    /**
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    private function parseRange(): array
    {
        try {
            return [
                $this->from !== '' ? Date::parse($this->from)->startOfDay() : null,
                $this->to !== '' ? Date::parse($this->to)->endOfDay() : null,
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row, ?CarbonInterface $fromTs, ?CarbonInterface $toTs): bool
    {
        if ($this->connection !== '') {
            $rowConn = $row['connection'] ?? '';
            if (! is_string($rowConn) || stripos($rowConn, $this->connection) === false) {
                return false;
            }
        }

        if ($this->queue !== '') {
            $rowQueue = $row['queue'] ?? '';
            if (! is_string($rowQueue) || stripos($rowQueue, $this->queue) === false) {
                return false;
            }
        }

        if (! $fromTs instanceof CarbonInterface && ! $toTs instanceof CarbonInterface) {
            return true;
        }

        $processedAt = $row['processed_at'] ?? null;
        if (! is_string($processedAt) || $processedAt === '') {
            return false;
        }

        try {
            $ts = Date::parse($processedAt);
        } catch (Throwable) {
            return false;
        }

        return ! ($fromTs instanceof CarbonInterface && $ts->lt($fromTs)) && ! ($toTs instanceof CarbonInterface && $ts->gt($toTs));
    }
}
