<?php declare(strict_types=1);

/**
 * Boots a Testbench app with package discovery disabled — so Livewire's
 * own service provider never registers — while the dashboard is left
 * enabled, then prints `booted`.
 *
 * The provider registers its Livewire components from an `app->booted`
 * callback and skips them when the `livewire` binding is absent. Without
 * that guard this boot dies on `Target class [livewire.finder] does not
 * exist`, which is how it failed under Larastan's bootstrap in CI.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;

$basePath = dirname(__DIR__, 2) . '/vendor/orchestra/testbench-core/laravel';

$appKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
putenv("APP_KEY={$appKey}");
$_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $appKey;

$app = TestbenchApplication::create(
    basePath: $basePath,
    options: [
        'enables_package_discoveries' => false,
    ],
);

config()->set('queue-insights.dashboard.enabled', true);

$app->register(QueueInsightsServiceProvider::class);
$app->make(ConsoleKernel::class)->bootstrap();

echo "booted\n";

exit(0);
