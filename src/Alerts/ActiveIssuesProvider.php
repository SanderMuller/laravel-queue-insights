<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Alerts;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

/**
 * Dashboard read path for active alerts. Wraps `IssueDetector::detectAll()`
 * with a request-lifetime memoise + a 5s Redis cache (`alert:cache:active-issues`)
 * so concurrent dashboard tabs don't multiply detector cost — bounded thunder
 * herd per spec §4.
 *
 * The snapshot command does NOT read this provider — it always runs the
 * detector fresh against live state so cooldown decisions reflect truth.
 */
final class ActiveIssuesProvider
{
    private const int CACHE_TTL_SECONDS = 5;

    private const string CACHE_KEY = 'alert:cache:active-issues';

    /**
     * @var list<Issue>|null
     */
    private ?array $memoised = null;

    public function __construct(
        private readonly IssueDetector $detector,
    ) {}

    /**
     * @return list<Issue>
     */
    public function get(?string $scopeConnection = null): array
    {
        $issues = $this->memoised ?? $this->readCache();

        if ($issues === null) {
            $issues = $this->detector->detectAll();
            $this->writeCache($issues);
        }

        $this->memoised = $issues;

        if ($scopeConnection === null) {
            return $issues;
        }

        // Class-scoped detectors (failure_rate, slow_p95) construct issues
        // with an empty `connection` field — those are aggregate by design
        // and should still surface under any scope. Queue-scoped issues
        // filter by exact connection match.
        return array_values(array_filter(
            $issues,
            static fn (Issue $i): bool => $i->connection === '' || $i->connection === $scopeConnection,
        ));
    }

    /**
     * Test seam — drop the per-request memoise without touching Redis.
     */
    public function flushMemoised(): void
    {
        $this->memoised = null;
    }

    /**
     * @return list<Issue>|null
     */
    private function readCache(): ?array
    {
        try {
            $raw = $this->redis()->command('get', [KeyPrefix::make(self::CACHE_KEY)]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: active-issues cache read failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<array-key, mixed> $row */
            $issue = $this->hydrate($row);
            if ($issue instanceof Issue) {
                $out[] = $issue;
            }
        }

        return $out;
    }

    /**
     * @param  list<Issue>  $issues
     */
    private function writeCache(array $issues): void
    {
        try {
            $payload = json_encode(array_map($this->dehydrate(...), $issues));
            if ($payload === false) {
                return;
            }

            $this->redis()->command('setex', [
                KeyPrefix::make(self::CACHE_KEY),
                self::CACHE_TTL_SECONDS,
                $payload,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: active-issues cache write failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return array{rule: string, severity: string, connection: string, queue: string, jobClass: ?string, title: string, description: string, context: array<string, mixed>, detectedAt: int}
     */
    private function dehydrate(Issue $issue): array
    {
        return [
            'rule' => $issue->rule,
            // Persist the raw enum value (`'critical'` / `'warning'`) so
            // the cache shape stays stable for ops introspection AND so
            // hydrate() can re-resolve via tryFrom on the next read.
            'severity' => $issue->severity->value,
            'connection' => $issue->connection,
            'queue' => $issue->queue,
            'jobClass' => $issue->jobClass,
            'title' => $issue->title,
            'description' => $issue->description,
            'context' => $issue->context,
            'detectedAt' => $issue->detectedAt,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function hydrate(array $row): ?Issue
    {
        $rule = $row['rule'] ?? null;
        $severityRaw = $row['severity'] ?? null;
        $connection = $row['connection'] ?? null;
        $queue = $row['queue'] ?? null;
        $title = $row['title'] ?? null;
        $description = $row['description'] ?? null;
        $context = $row['context'] ?? null;
        $detectedAt = $row['detectedAt'] ?? null;

        if (! is_string($rule) || ! is_string($severityRaw)
            || ! is_string($connection) || ! is_string($queue)
            || ! is_string($title) || ! is_string($description)
            || ! is_array($context) || ! is_int($detectedAt)
        ) {
            return null;
        }

        $severity = AlertSeverity::tryFrom($severityRaw);
        if ($severity === null) {
            return null;
        }

        $jobClassRaw = $row['jobClass'] ?? null;
        $jobClass = is_string($jobClassRaw) ? $jobClassRaw : null;

        /** @var array<string, mixed> $context */
        return new Issue(
            rule: $rule,
            severity: $severity,
            connection: $connection,
            queue: $queue,
            jobClass: $jobClass,
            title: $title,
            description: $description,
            context: $context,
            detectedAt: $detectedAt,
        );
    }

    private function redis(): RedisConnection
    {
        // Date dependency kept resolved upfront so test stubs that freeze
        // time before the first call don't see a re-resolved Date facade.
        Date::now();

        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
