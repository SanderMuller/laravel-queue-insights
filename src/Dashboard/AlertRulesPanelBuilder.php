<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;

/**
 * Read-only resolved view of `alerts.rules` + `alerts.channels` for the
 * dashboard's "Alert rules" panel. The panel is informational — the source
 * of truth remains the host's published config; this builder just flattens
 * the nested arrays into rows the Blade partial can iterate without
 * re-reading config keys per cell.
 */
final readonly class AlertRulesPanelBuilder
{
    /**
     * Per-rule default-enabled state, mirrored from each detector's
     * runtime check (`Config::bool('alerts.rules.X.enabled', $default)`).
     * Most rules default-on; `slow_p95` and `backlog_growing` are opt-in.
     * Without this, the panel rendered "off" for any rule whose published
     * config omitted the explicit `enabled` key — misleading the operator
     * about what would actually fire.
     *
     * @var array<string, bool>
     */
    private const array RULE_DEFAULTS_ENABLED = [
        'depth' => true,
        'stalled' => true,
        'oldest_pending' => true,
        'stuck_inflight' => true,
        'failure_rate' => true,
        'slow_p95' => false,
        'snapshot_errored' => true,
        'backlog_growing' => false,
    ];

    /**
     * @return array{
     *     enabled: bool,
     *     cooldown_seconds: int,
     *     rules: list<array{key: string, enabled: bool, severity: ?AlertSeverity, params: list<array{label: string, value: string}>}>,
     *     channels: list<array{key: string, enabled: bool, detail: string}>,
     *     legacy_thresholds_in_use: bool,
     * }
     */
    public function build(): array
    {
        return [
            'enabled' => Config::bool('alerts.enabled', false),
            'cooldown_seconds' => Config::int('alerts.cooldown_seconds', 900),
            'rules' => $this->rules(),
            'channels' => $this->channels(),
            'legacy_thresholds_in_use' => Config::array('alerts.thresholds') !== [],
        ];
    }

    /**
     * @return list<array{key: string, enabled: bool, severity: ?AlertSeverity, params: list<array{label: string, value: string}>}>
     */
    private function rules(): array
    {
        $rules = Config::array('alerts.rules');

        $out = [];
        foreach (['depth', 'stalled', 'oldest_pending', 'stuck_inflight', 'failure_rate', 'slow_p95', 'snapshot_errored', 'backlog_growing'] as $key) {
            $candidate = $rules[$key] ?? null;
            /** @var array<string, mixed> $rule */
            $rule = is_array($candidate) ? $candidate : [];

            $defaultEnabled = self::RULE_DEFAULTS_ENABLED[$key];
            $explicit = $rule['enabled'] ?? null;
            $enabled = is_bool($explicit) ? $explicit : $defaultEnabled;

            // Surface as `AlertSeverity|null` so the blade can compare
            // against enum cases (`=== AlertSeverity::Critical`) instead
            // of stringly-typed equality. Null = the rule's per-row
            // severity wasn't configured (e.g. `depth` carries severity
            // per threshold, not per rule).
            $severity = is_string($rule['severity'] ?? null)
                ? AlertSeverity::tryFrom($rule['severity'])
                : null;

            $out[] = [
                'key' => $key,
                'enabled' => $enabled,
                'severity' => $severity,
                'params' => $this->paramsFor($key, $rule),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<array{label: string, value: string}>
     */
    private function paramsFor(string $key, array $rule): array
    {
        return match ($key) {
            'depth' => [
                ['label' => 'thresholds', 'value' => $this->formatDepthThresholds($rule)],
            ],
            'stalled' => [
                ['label' => 'idle_seconds', 'value' => $this->scalar($rule['idle_seconds'] ?? null)],
                ['label' => 'min_depth', 'value' => $this->scalar($rule['min_depth'] ?? null)],
            ],
            'oldest_pending', 'stuck_inflight' => [
                ['label' => 'seconds', 'value' => $this->scalar($rule['seconds'] ?? null)],
            ],
            'failure_rate' => [
                ['label' => 'min_jobs', 'value' => $this->scalar($rule['min_jobs'] ?? null)],
                ['label' => 'ratio', 'value' => $this->scalar($rule['ratio'] ?? null)],
            ],
            'slow_p95' => [
                ['label' => 'classes', 'value' => $this->formatClassThresholds($rule)],
            ],
            'backlog_growing' => [
                ['label' => 'min_slope_per_minute', 'value' => $this->scalar($rule['min_slope_per_minute'] ?? null)],
                ['label' => 'min_samples', 'value' => $this->scalar($rule['min_samples'] ?? null)],
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function formatDepthThresholds(array $rule): string
    {
        // Resolve via the same legacy-wins path as DepthDetector so the
        // panel reflects what will actually fire.
        $legacy = Config::array('alerts.thresholds');
        $entries = $legacy !== [] ? $legacy : (is_array($rule['thresholds'] ?? null) ? $rule['thresholds'] : []);

        if ($entries === []) {
            return '(none)';
        }

        $parts = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $conn = is_string($entry['connection'] ?? null) ? $entry['connection'] : '?';
            $queue = is_string($entry['queue'] ?? null) ? $entry['queue'] : '?';
            $depth = $this->scalar($entry['depth'] ?? null);
            $sev = is_string($entry['severity'] ?? null) ? $entry['severity'] : 'warning';
            $parts[] = "{$conn}:{$queue}≥{$depth} ({$sev})";
        }

        return $parts === [] ? '(none)' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function formatClassThresholds(array $rule): string
    {
        $map = is_array($rule['class_threshold_ms'] ?? null) ? $rule['class_threshold_ms'] : [];
        if ($map === []) {
            return '(no classes configured)';
        }

        $parts = [];
        foreach ($map as $class => $ms) {
            if (! is_string($class)) {
                continue;
            }

            if (! is_int($ms)) {
                continue;
            }

            $parts[] = "{$class}≥{$ms}ms";
        }

        return $parts === [] ? '(no classes configured)' : implode(', ', $parts);
    }

    /**
     * @return list<array{key: string, enabled: bool, detail: string}>
     */
    private function channels(): array
    {
        $log = Config::bool('alerts.channels.log.enabled', true);
        $level = Config::string('alerts.channels.log.level', 'warning');

        $mailEnabled = Config::bool('alerts.channels.mail.enabled', false);
        $mailTo = Config::array('alerts.channels.mail.to');
        $mailDetail = $mailEnabled
            ? sprintf('to: %s', $mailTo === [] ? '(unset)' : implode(', ', array_filter($mailTo, is_string(...))))
            : 'disabled';

        $slackEnabled = Config::bool('alerts.channels.slack.enabled', false);
        $slackUrl = Config::string('alerts.channels.slack.webhook_url', '');
        $slackDetail = $slackEnabled
            ? ($slackUrl === '' ? 'webhook URL unset' : 'webhook configured')
            : 'disabled';

        return [
            ['key' => 'log', 'enabled' => $log, 'detail' => "level: {$level}"],
            ['key' => 'mail', 'enabled' => $mailEnabled, 'detail' => $mailDetail],
            ['key' => 'slack', 'enabled' => $slackEnabled, 'detail' => $slackDetail],
        ];
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) && $value !== '' => $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => '—',
        };
    }
}
