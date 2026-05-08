<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Scheduler\ScheduleReader;
use SanderMuller\QueueInsights\Support\Config;

final class QueueInsightsScheduleListCommand extends Command
{
    protected $signature = 'queue-insights:schedule:list';

    protected $description = 'List scheduled tasks captured by queue-insights with their last-run + counter state.';

    public function __construct(private readonly ScheduleReader $reader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Config::bool('scheduler.enabled', false)) {
            $this->warn('Scheduler observability is disabled. Set queue-insights.scheduler.enabled = true to enable.');

            return self::SUCCESS;
        }

        $tasks = $this->reader->tasks();
        if ($tasks === []) {
            $this->info('No scheduled tasks captured. Snapshot is rebuilt on every app boot — restart workers / php-fpm and try again.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $counters = $this->reader->counters($task['task_key']);
            $rows[] = [
                $this->shortKey($task['task_key']),
                $task['description'] ?? $task['command'],
                $task['expression'],
                $task['type'],
                $this->yesNo($task['runInBackground']),
                $this->yesNo($task['onOneServer']),
                (string) $counters['total_runs'],
                (string) $counters['total_failed'],
                (string) $counters['total_skipped'],
                $this->formatTime($counters['last_run_at']),
            ];
        }

        $this->table(
            ['key', 'task', 'cron', 'type', 'bg', '1srv', 'runs', 'failed', 'skipped', 'last run'],
            $rows,
        );

        $snapshotAt = $this->reader->snapshotAtMs();
        $this->line(sprintf(
            '<fg=gray>Snapshot captured at %s.</>',
            $snapshotAt === null ? 'never' : $this->formatTime($snapshotAt),
        ));

        return self::SUCCESS;
    }

    private function shortKey(string $key): string
    {
        return substr($key, 0, 8);
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function formatTime(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }

        return Date::createFromTimestamp((int) ($ms / 1000))->format('Y-m-d H:i:s');
    }
}
