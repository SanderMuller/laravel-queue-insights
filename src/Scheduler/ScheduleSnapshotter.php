<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\LuaScripts;
use SanderMuller\QueueInsights\Support\RedisEval;
use Throwable;

/**
 * Rebuilds the per-task definition snapshot at boot.
 *
 * Why on boot: `Schedule::events()` is only fully populated after every
 * provider has registered tasks (Laravel 11+ `routes/console.php`,
 * package providers, `withSchedule()`, `app/Console/Kernel.php`).
 * Capturing in `app->booted` guarantees the full set.
 *
 * Idempotent — if the snapshot hash matches the previous boot's value
 * the rebuild is a no-op.
 */
final readonly class ScheduleSnapshotter
{
    public function __construct(private Schedule $schedule) {}

    public function rebuild(): void
    {
        try {
            $schedule = $this->schedule;

            /** @var list<Event> $events */
            $events = array_values($schedule->events());

            $summaries = [];
            foreach ($events as $event) {
                $summaries[TaskKey::for($event)] = TaskSummariser::summarise($event);
            }

            $hash = hash('sha256', (string) json_encode($summaries));
            $hashKey = KeyPrefix::make('sched:snapshot:hash');
            $atKey = KeyPrefix::make('sched:snapshot:at');
            $tasksKey = KeyPrefix::make('sched:tasks');
            $orderKey = KeyPrefix::make('sched:tasks:order');

            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            $existing = $redis->command('get', [$hashKey]);
            if (is_string($existing) && $existing === $hash) {
                return;
            }

            // Atomic full rewrite via Lua — DEL+RPUSH was previously
            // four-or-more separate round-trips, and two concurrent
            // boots (FPM + queue workers after a deploy) could
            // interleave their writes and produce duplicate entries
            // in the order list. The script wraps everything in one
            // call so concurrent rebuilders become last-writer-wins.
            // Removed tasks drop off the hash + order list; their
            // per-task counters and historical run keys age out via
            // TTL.
            $argv = [$hash, (string) Date::now()->getTimestampMs()];
            foreach ($summaries as $key => $summary) {
                $argv[] = $key;
                $argv[] = (string) json_encode($summary);
            }

            RedisEval::exec(
                $redis,
                LuaScripts::rewriteScheduleSnapshot(),
                4,
                $tasksKey,
                $orderKey,
                $hashKey,
                $atKey,
                ...$argv,
            );
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: ScheduleSnapshotter::rebuild failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
