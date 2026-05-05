<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use SanderMuller\QueueInsights\QueueInsights;
use SanderMuller\QueueInsights\Support\BatchReader;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;
use Workbench\App\Support\PreviewSeeder;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
});

it('PreviewSeeder hydrates the dashboard surface against real Redis state', function (): void {
    // The workbench preview now seeds Redis (and the failed_jobs DB table)
    // and renders the REAL dashboard against that state. This smoke test
    // exercises the full seed path so a missing key family surfaces here
    // instead of as a blank section in the running preview.
    resolve(PreviewSeeder::class)->seed();

    $redis = Redis::connection('default');

    // Snapshots — at least one queue's live:depth landed.
    expect($redis->command('get', ['qmpreview:live:depth:redis:default']))->toBe('12');

    // Completed stream — the chain backbone (root + mid + tail) all have
    // entries, including the parent_uuid stamps that drive `↰ From`.
    $entries = $redis->command('xrange', ['qmpreview:completed', '-', '+']);
    expect($entries)->toBeArray()->not->toBeEmpty();

    // qi:class:{uuid} index — hydrates parent_class on the modal.
    expect($redis->command('get', ['qmpreview:class:preview-uuid-process-import']))
        ->toBe('App\\Jobs\\ProcessImport');

    // qi:lineage:{child-uuid} — failed-row backward link side channel.
    expect($redis->command('get', ['qmpreview:lineage:preview-uuid-failed-child']))
        ->toBe('preview-uuid-process-import');

    // failed_jobs table — production read path is DB, not Redis. Three
    // un-silenced rows + two silenced (`PingThirdPartyVendor`) demonstrate
    // the silenced-jobs filter — the Failed list hides the latter pair
    // by default but they still occupy DB rows.
    expect(DB::table('failed_jobs')->count())->toBe(5);

    // Pending + delayed counts calibrated via the live readers so the
    // headline `pendingPreview` strip (cap 5) shows in-flight + pending
    // + at least one delayed: 2 in-flight + 2 pending + 2 delayed = 6
    // candidates, capped at 5, last delayed clipped, one still surfaces.
    config()->set('queue-insights.key_prefix', 'qmpreview:');
    config()->set('queue-insights.snapshots', [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'mail'],
        ['connection' => 'sqs', 'queue' => 'reports'],
    ]);
    $svc = resolve(QueueInsights::class);
    expect($svc->allInFlightJobs())->toHaveCount(2)
        ->and($svc->allPendingJobs())->toHaveCount(2)
        ->and($svc->allDelayedJobs())->toHaveCount(2);
});

it('seeded batches survive into Bus::findBatch / BatchReader::recentBatches', function (): void {
    resolve(PreviewSeeder::class)->seed();

    $count = DB::table('job_batches')->count();
    expect($count)->toBe(2);

    $batches = BatchReader::recentBatches(50);
    expect($batches)->toHaveCount(2);
});

it('PreviewSeeder is idempotent within a single request', function (): void {
    // The middleware fires once per request but Livewire polls re-enter
    // the same route. The singleton-bound seeder must short-circuit so
    // the fresh-flush doesn't lose interim state.
    $seeder = resolve(PreviewSeeder::class);
    $seeder->seed();

    $firstSize = Redis::connection('default')
        ->command('xlen', ['qmpreview:completed']);

    $seeder->seed();
    $secondSize = Redis::connection('default')
        ->command('xlen', ['qmpreview:completed']);

    expect($secondSize)->toBe($firstSize);
});
