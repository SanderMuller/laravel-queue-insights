<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

/**
 * Headline stats — derived from data already loaded for the dashboard,
 * no extra Redis round-trips.
 *
 * @internal
 */
final readonly class HeadlineStatsBuilder
{
    /**
     * @param  list<array{timestamp: int, processed: int, failed: int}>  $throughput
     * @param  list<array<string, mixed>>  $queues
     * @param  list<array<string, mixed>>  $classes
     * @return array{
     *     jobs_per_minute: int,
     *     jobs_past_hour: int,
     *     failed_past_hour: int,
     *     max_throughput_hour: int,
     *     max_wait_ms: ?int,
     *     max_runtime_ms: ?int,
     * }
     */
    public function build(array $throughput, array $queues, array $classes): array
    {
        $latest = $throughput === [] ? ['processed' => 0, 'failed' => 0] : $throughput[count($throughput) - 1];
        $pastHour = $latest['processed'];

        $processedSeries = array_column($throughput, 'processed');

        return [
            'jobs_per_minute' => (int) round($pastHour / 60),
            'jobs_past_hour' => $pastHour,
            'failed_past_hour' => $latest['failed'],
            'max_throughput_hour' => $processedSeries === [] ? 0 : max($processedSeries),
            'max_wait_ms' => $this->maxIntCol($queues, 'wait_p95_ms'),
            'max_runtime_ms' => $this->maxIntCol($classes, 'p95_ms'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function maxIntCol(array $rows, string $key): ?int
    {
        $values = [];
        foreach ($rows as $row) {
            $v = $row[$key] ?? null;
            if (is_numeric($v)) {
                $values[] = (int) $v;
            }
        }

        return $values === [] ? null : max($values);
    }
}
