<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Dashboard;

use Illuminate\Support\Facades\Date;
use SanderMuller\QueueInsights\Alerts\Issue;
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
     * @param  list<Issue>|null  $activeIssues  current detected issues (already
     *                                          scope-filtered by the caller via
     *                                          `ActiveIssuesProvider::get()`).
     *                                          Null means "don't render firing
     *                                          state" — keeps the builder usable
     *                                          from contexts without a provider.
     * @return array{
     *     enabled: bool,
     *     cooldown_seconds: int,
     *     rules: list<array{key: string, enabled: bool, severity: ?AlertSeverity, params: list<array{label: string, value: string}>, firing_count: int, firing_severity: ?AlertSeverity, firing_issues: list<array{target: string, target_type: string, title: string, description: string, severity: AlertSeverity, age_seconds: int, context: array<string, scalar>}>}>,
     *     channels: list<array{key: string, enabled: bool, detail: string}>,
     *     legacy_thresholds_in_use: bool,
     * }
     */
    public function build(?string $scopeConnection = null, ?array $activeIssues = null): array
    {
        return [
            'enabled' => Config::bool('alerts.enabled', false),
            'cooldown_seconds' => Config::int('alerts.cooldown_seconds', 900),
            'rules' => $this->rules($scopeConnection, $activeIssues ?? []),
            'channels' => $this->channels(),
            'legacy_thresholds_in_use' => Config::array('alerts.thresholds') !== [],
        ];
    }

    /**
     * @param  list<Issue>  $activeIssues
     * @return list<array{key: string, enabled: bool, severity: ?AlertSeverity, params: list<array{label: string, value: string}>, firing_count: int, firing_severity: ?AlertSeverity, firing_issues: list<array{target: string, target_type: string, title: string, description: string, severity: AlertSeverity, age_seconds: int, context: array<string, scalar>}>}>
     */
    private function rules(?string $scopeConnection, array $activeIssues): array
    {
        $firingByRule = $this->aggregateFiring($activeIssues);

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

            $firing = $firingByRule[$key] ?? ['count' => 0, 'severity' => null, 'issues' => []];

            $out[] = [
                'key' => $key,
                'enabled' => $enabled,
                'severity' => $severity,
                'params' => $this->paramsFor($key, $rule, $scopeConnection),
                'firing_count' => $firing['count'],
                'firing_severity' => $firing['severity'],
                'firing_issues' => $firing['issues'],
            ];
        }

        return $out;
    }

    /**
     * Aggregate `$activeIssues` by rule key. Counts and severity come from
     * the issues as-passed; scope filtering is the caller's job (handled
     * by `ActiveIssuesProvider::get($scopeConnection)`). Class-scoped issues
     * (`connection===''`, e.g. failure_rate / slow_p95) survive every scope
     * filter by design and are shown in both the alerts strip and the panel
     * — the panel mirrors the strip so a scoped operator does not see a red
     * strip alongside an "All alarms OK" panel.
     *
     * @param  list<Issue>  $activeIssues
     * @return array<string, array{count: int, severity: ?AlertSeverity, issues: list<array{target: string, target_type: string, title: string, description: string, severity: AlertSeverity, age_seconds: int, context: array<string, scalar>}>}>
     */
    private function aggregateFiring(array $activeIssues): array
    {
        $now = Date::now()
            ->getTimestamp();
        $out = [];
        foreach ($activeIssues as $issue) {
            $rule = $issue->rule;
            $current = $out[$rule] ?? ['count' => 0, 'severity' => null, 'issues' => []];
            ++$current['count'];
            $current['issues'][] = $this->flattenIssue($issue, $now);

            $existing = $current['severity'];
            if ($existing === null || ($issue->severity === AlertSeverity::Critical && $existing !== AlertSeverity::Critical)) {
                $current['severity'] = $issue->severity;
            }

            $out[$rule] = $current;
        }

        return $out;
    }

    /**
     * Pre-flatten the (internal) `Issue` value object into a blade-friendly
     * row so the panel's contract doesn't leak `Issue` itself into the
     * dashboard surface.
     *
     * @return array{target: string, target_type: string, title: string, description: string, severity: AlertSeverity, age_seconds: int, context: array<string, scalar>}
     */
    private function flattenIssue(Issue $issue, int $now): array
    {
        $context = [];
        foreach ($issue->context as $k => $v) {
            if (is_scalar($v)) {
                $context[$k] = $v;
            }
        }

        return [
            'target' => $issue->jobClass ?? "{$issue->connection}:{$issue->queue}",
            'target_type' => $issue->jobClass !== null ? 'class' : 'queue',
            'title' => $issue->title,
            'description' => $issue->description,
            'severity' => $issue->severity,
            'age_seconds' => max(0, $now - $issue->detectedAt),
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<array{label: string, value: string}>
     */
    private function paramsFor(string $key, array $rule, ?string $scopeConnection): array
    {
        return match ($key) {
            'depth' => [
                ['label' => 'thresholds', 'value' => $this->formatDepthThresholds($rule, $scopeConnection)],
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
    private function formatDepthThresholds(array $rule, ?string $scopeConnection): string
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
            if ($scopeConnection !== null && $conn !== $scopeConnection) {
                continue;
            }

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
        $slackChannel = Config::string('alerts.channels.slack.channel', '');
        $slackDetail = match (true) {
            ! $slackEnabled => 'disabled',
            $slackUrl === '' => 'webhook URL unset',
            $slackChannel !== '' => "channel: {$slackChannel}",
            default => sprintf('webhook: %s', $this->slackWebhookFingerprint($slackUrl)),
        };

        return [
            ['key' => 'log', 'enabled' => $log, 'detail' => "level: {$level}"],
            ['key' => 'mail', 'enabled' => $mailEnabled, 'detail' => $mailDetail],
            ['key' => 'slack', 'enabled' => $slackEnabled, 'detail' => $slackDetail],
        ];
    }

    /**
     * Stable, non-secret fingerprint for a Slack incoming-webhook URL —
     * 8 hex chars of `sha256(url)`. Lets operators tell two configured
     * webhooks apart in screenshots and incident chats without revealing
     * any substring of the secret token. The channel itself is bound at
     * webhook creation time on Slack's side and is not derivable from the
     * URL, so an explicit `alerts.channels.slack.channel` config key is
     * the only way to surface the channel name in this panel.
     */
    private function slackWebhookFingerprint(string $url): string
    {
        return substr(hash('sha256', $url), 0, 8);
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
