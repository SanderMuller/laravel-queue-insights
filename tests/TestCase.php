<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [
            LivewireServiceProvider::class,
            QueueInsightsServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment(mixed $app): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);

        $config->set('app.env', 'testing');
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('queue-insights.snapshots', []);
        $config->set('queue-insights.key_prefix', 'qm:testing:');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $host = getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');
        $db = getenv('REDIS_DB');

        // QI_REDIS_CLIENT=phpredis in CI matrix to exercise ext-redis path (XADD/XREVRANGE
        // signature divergence vs Predis — see RecordJobProcessed::xaddApprox). We use our
        // own var instead of REDIS_CLIENT because testbench puts REDIS_CLIENT=phpredis into
        // the process env during bootstrap, which would otherwise flip the default silently.
        $client = getenv('QI_REDIS_CLIENT');
        $config->set('database.redis.client', is_string($client) && $client !== '' ? $client : 'predis');
        $config->set('database.redis.default', [
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_string($port) && is_numeric($port) ? (int) $port : 6379,
            'database' => is_string($db) && is_numeric($db) ? (int) $db : 15,
        ]);

        // Optional Redis Cluster connection — only wired up when the
        // `cluster` CI lane exports REDIS_CLUSTER_HOST; the `cluster` test
        // group skips otherwise. The cluster-mode option goes under the
        // `clusters`-scoped config subtree, NOT global `database.redis.options`,
        // so `default` stays a plain connection even on the cluster lane.
        $clusterHost = getenv('REDIS_CLUSTER_HOST');
        if (is_string($clusterHost) && $clusterHost !== '') {
            $clusterPort = getenv('REDIS_CLUSTER_PORT');
            $config->set('database.redis.clusters.options', ['cluster' => 'redis']);
            $config->set('database.redis.clusters.cluster', [
                [
                    'host' => $clusterHost,
                    'port' => is_string($clusterPort) && is_numeric($clusterPort) ? (int) $clusterPort : 7000,
                ],
            ]);
        }

        $config->set('queue.default', 'sync');
        $config->set('queue.connections.sync', ['driver' => 'sync']);

        $config->set('cache.default', 'array');

        // Prometheus needs the gate flipped BEFORE the provider boots so
        // `registerPrometheus()` loads the route file and registers the
        // middleware alias. Tests that don't touch Prometheus aren't
        // affected — the route only listens at `/metrics` and the
        // collectors are zero-cost when nothing scrapes them. Per-test
        // toggles (token, cache TTL) override via `config()->set` in
        // beforeEach.
        $config->set('queue-insights.prometheus.enabled', true);
        $config->set('queue-insights.prometheus.cache_ttl_seconds', 0);
    }
}
