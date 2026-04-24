<?php

declare(strict_types=1);

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
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            QueueInsightsServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
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

        $config->set('queue.default', 'sync');
        $config->set('queue.connections.sync', ['driver' => 'sync']);

        $config->set('cache.default', 'array');
    }
}
