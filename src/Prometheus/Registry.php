<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\Prometheus\Collectors\ExporterSelfCollector;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Support\Config;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use Throwable;

/**
 * Drives the scrape pipeline: collectors → families → renderer →
 * cached exposition text. Two-tier cache:
 *
 *   1. Per-instance memoise (by flavour) — keeps a single request's
 *      first/second render from re-collecting state.
 *   2. Redis cache `prom:cache:rendered:{flavour}` (TTL =
 *      `cache_ttl_seconds`) — bounds thunder-herd when several
 *      Prometheus replicas scrape concurrently.
 *
 * Bound with `bind()` (per-request resolution) — never `singleton`.
 * A persistent-process container (Octane / Swoole / RoadRunner)
 * would otherwise leak the previous request's memoise into a fresh
 * scrape. Mirrors `ActiveIssuesProvider`'s binding pattern.
 *
 * @internal
 */
final class Registry
{
    /**
     * @var array<string, string>
     */
    private array $memoisedRendered = [];

    /**
     * @param  list<Collector>  $collectors
     */
    public function __construct(
        private readonly array $collectors,
        private readonly Renderer $renderer,
        private readonly ExporterSelfCollector $selfCollector,
    ) {}

    public function render(bool $openmetrics = false): string
    {
        $flavour = $openmetrics ? 'openmetrics' : 'text';

        if (isset($this->memoisedRendered[$flavour])) {
            return $this->memoisedRendered[$flavour];
        }

        $ttl = Config::int('prometheus.cache_ttl_seconds', 5);
        if ($ttl > 0) {
            $cached = $this->readCache($flavour);
            if ($cached !== null) {
                return $this->memoisedRendered[$flavour] = $cached;
            }
        }

        $rendered = $this->renderer->render($this->collect(), $openmetrics);

        if ($ttl > 0) {
            $this->writeCache($flavour, $rendered, $ttl);
        }

        return $this->memoisedRendered[$flavour] = $rendered;
    }

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $families = [];

        $start = microtime(true);
        foreach ($this->collectors as $collector) {
            if (! $collector->isEnabled()) {
                continue;
            }

            try {
                foreach ($collector->collect() as $family) {
                    $families[] = $family;
                }
            } catch (Throwable $throwable) {
                // One broken collector must not poison the whole scrape —
                // emit a log and skip its families.
                Log::warning('queue-insights: prometheus collector failed', [
                    'collector' => $collector::class,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        $this->selfCollector->record(microtime(true) - $start);

        // Self-collector samples LAST so the gauge reflects the prior
        // in-cycle work. Honour its own toggle so a host opting out
        // (via a future config knob) drops cleanly.
        if ($this->selfCollector->isEnabled()) {
            foreach ($this->selfCollector->collect() as $family) {
                $families[] = $family;
            }
        }

        return $families;
    }

    /**
     * Test seam — drop the per-flavour render memoise without touching
     * the Redis cache layer.
     */
    public function flushMemoised(): void
    {
        $this->memoisedRendered = [];
    }

    private function readCache(string $flavour): ?string
    {
        try {
            $raw = $this->redisCommand('get', [KeyPrefix::make("prom:cache:rendered:{$flavour}")]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: prometheus cache read failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function writeCache(string $flavour, string $body, int $ttl): void
    {
        try {
            $this->redisCommand('setex', [
                KeyPrefix::make("prom:cache:rendered:{$flavour}"),
                $ttl,
                $body,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('queue-insights: prometheus cache write failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<int|string>  $args
     */
    private function redisCommand(string $command, array $args): mixed
    {
        return Redis::connection(Config::string('redis_connection', 'default'))
            ->command($command, $args);
    }
}
