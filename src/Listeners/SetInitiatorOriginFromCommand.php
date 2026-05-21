<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

/**
 * Stamps the coarse artisan origin onto hidden Laravel `Context` so jobs
 * dispatched during an artisan command carry `artisan:{command}` into
 * their payload.
 *
 * Long-running daemons that process *other people's* jobs are skipped so
 * the daemon's own name never leaks as an origin (spec §3.1) — without
 * this, every job a worker runs would be attributed `artisan:queue:work`.
 */
final class SetInitiatorOriginFromCommand
{
    /**
     * Daemon commands whose name must never become a job origin: they
     * run *other* jobs, so the dispatching command is the real origin.
     *
     * @var list<string>
     */
    private const array DAEMON_COMMANDS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'queue-insights:work',
        'schedule:work',
    ];

    public function handle(CommandStarting $event): void
    {
        try {
            if (! Config::bool('initiator.enabled', true) || ! Config::bool('initiator.capture_origin', true)) {
                return;
            }

            $command = $event->command;
            if ($command === '') {
                return;
            }

            if (in_array($command, self::DAEMON_COMMANDS, true)) {
                return;
            }

            Context::addHidden(
                Config::string('initiator.context_key', 'qi_origin'),
                'artisan:' . $command,
            );
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: SetInitiatorOriginFromCommand failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
