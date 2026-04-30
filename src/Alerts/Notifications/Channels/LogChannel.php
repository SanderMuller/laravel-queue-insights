<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Alerts\Notifications\QueueAlertNotification;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Custom Notification channel that writes a structured log line. Zero-dep —
 * `illuminate/log` lives in the framework's tree.
 */
final class LogChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof QueueAlertNotification) {
            return;
        }

        $issue = $notification->issue;

        Log::log($this->level(), 'queue-insights alert: ' . $issue->title, [
            'rule' => $issue->rule,
            'severity' => $issue->severity->value,
            'connection' => $issue->connection,
            'queue' => $issue->queue,
            'job_class' => $issue->jobClass,
            'description' => $issue->description,
            'context' => $issue->context,
            'detected_at' => $issue->detectedAt,
        ]);
    }

    private function level(): string
    {
        $level = Config::string('alerts.channels.log.level', 'warning');

        return $level === '' ? 'warning' : $level;
    }
}
