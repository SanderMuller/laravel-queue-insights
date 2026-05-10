<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Http\Livewire\ScheduleInsightsPanel;
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
    // un-silenced rows + one silenced (`PingThirdPartyVendor`) demonstrate
    // the silenced-jobs filter — the Failed list hides the latter by
    // default but it still occupies a DB row. Silenced-class success
    // traffic lives on the completed stream (see seedCompletedStream).
    expect(DB::table('failed_jobs')->count())->toBe(4);

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
    // Pending + in-flight each include one retry-flagged row (`attempts > 1`)
    // so the preview demonstrates the orange "retry N" badge in the Pending
    // tab; Delayed retains the original 2 because a delayed retry is the
    // same visual state as a regular delayed.
    expect($svc->allInFlightJobs())->toHaveCount(3)
        ->and($svc->allPendingJobs())->toHaveCount(3)
        ->and($svc->allDelayedJobs())->toHaveCount(2);

    // Connection-scoped throughput must be non-zero on every seeded
    // connection. Regression: without per-connection counters the
    // /queue-insights/redis and /queue-insights/sqs charts both
    // rendered 0 while the unscoped All view showed thousands.
    $allProcessed = array_sum(array_column($svc->hourlyThroughput(24), 'processed'));
    $redisProcessed = array_sum(array_column($svc->hourlyThroughput(24, 'redis'), 'processed'));
    $sqsProcessed = array_sum(array_column($svc->hourlyThroughput(24, 'sqs'), 'processed'));

    expect($allProcessed)->toBeGreaterThan(0)
        ->and($redisProcessed)->toBeGreaterThan(0)
        ->and($sqsProcessed)->toBeGreaterThan(0)
        ->and($redisProcessed + $sqsProcessed)->toBe($allProcessed);

    // Silenced class `PingThirdPartyVendor` has 1 failure + many completed
    // entries — without the success traffic the Silenced tab's Completed
    // roster shows 0 even though the failure list has activity, which
    // misrepresents the "noisy-but-mostly-OK" shape silencing is for.
    $silencedFailedCount = DB::table('failed_jobs')
        ->where('payload', 'like', '%PingThirdPartyVendor%')
        ->count();
    $silencedCompletedEntries = $redis->command('xrange', [
        'qmpreview:completed:App\\Jobs\\PingThirdPartyVendor', '-', '+',
    ]);

    expect($silencedFailedCount)->toBe(1)
        ->and($silencedCompletedEntries)->toBeArray()->toHaveCount(50);

    // The Silenced tab's Failed list reads from `silencedFailedRows` which
    // filters `RowEnricher::failed()` output by `$row['class']`. Earlier
    // RowEnricher::failed only emitted `display_name` (not `class`), so
    // the filter never matched any silenced FQCN and the tab showed 0
    // failed rows even when the DB held seeded silenced failures.
    config()->set('queue-insights.silenced', ['App\\Jobs\\PingThirdPartyVendor']);
    $rows = Livewire::test(QueueInsightsDashboard::class)->viewData('silencedFailedRows');
    expect($rows)->toBeArray()->not->toBeEmpty()
        ->and($rows[0]['class'] ?? null)
        ->toBe('App\Jobs\PingThirdPartyVendor');
});

it('seeds scheduler tasks + runs + counters so the Schedule tab is fully demo-able', function (): void {
    resolve(PreviewSeeder::class)->seed();

    $redis = Redis::connection('default');

    // Six tasks land in the snapshot (5 commands + 1 closure).
    expect($redis->command('llen', ['qmpreview:sched:tasks:order']))->toBe(6)
        ->and($redis->command('hlen', ['qmpreview:sched:tasks']))->toBe(6);

    // Snapshot meta written.
    expect($redis->command('get', ['qmpreview:sched:snapshot:hash']))->toBeString()
        ->and($redis->command('get', ['qmpreview:sched:snapshot:at']))->toBeString();

    // Recent runs index has the exact seeded count: 5 historical per
    // task × 6 tasks = 30, plus the closure's synthetic skipped run, the
    // NightlyBackup hung run, and the SyncCustomers in-flight run = 33.
    // Tightened from `>=30` so a regression that drops the
    // skipped/hung/in-flight branches surfaces here instead of as a
    // missing row in the running preview.
    expect($redis->command('zcard', ['qmpreview:sched:runs:all']))->toBe(33);

    // Running-index zset carries the in-flight + hung tasks.
    expect($redis->command('zcard', ['qmpreview:sched:running-index']))->toBe(2);

    // Queue↔schedule attribution: the existing `preview-pending-1` hash
    // got the schedule frame stamped on it, and the run-jobs zset has
    // the dispatched uuids.
    expect($redis->command('hget', ['qmpreview:pending:preview-pending-1', 'schedule_run_id']))
        ->toBeString()
        ->toContain('preview-run-');
});

it('schedule panel hydrates against seeded preview state without error', function (): void {
    resolve(PreviewSeeder::class)->seed();

    Livewire::withoutLazyLoading();

    Livewire::test(ScheduleInsightsPanel::class)
        ->assertSee('Tasks')
        ->assertSee('Recent runs')
        ->assertSee('SyncCustomers')
        ->assertSee('NightlyBackup')
        ->assertSee('closure@routes/console.php:42')
        ->assertSee('Hourly throughput');
});

it('seeded batches survive into Bus::findBatch / BatchReader::recentBatches', function (): void {
    resolve(PreviewSeeder::class)->seed();

    $count = DB::table('job_batches')->count();
    expect($count)->toBe(2);

    $batches = BatchReader::recentBatches(50);
    expect($batches)->toHaveCount(2);
});

it('PreviewSeeder reseeds job_batches without UNIQUE-constraint crash across requests', function (): void {
    // Regression: each HTTP request resolves a fresh PreviewSeeder
    // singleton, so the in-process `seeded` guard does not protect the
    // job_batches table from a re-run on the next request. wire:navigate
    // prefetch made this routine. `updateOrInsert` raced — both requests
    // SELECTed empty, both INSERTed, the second crashed on the unique
    // primary key. Atomic upsert is the fix.
    resolve(PreviewSeeder::class)->seed();
    expect(DB::table('job_batches')->count())->toBe(2);

    // Fresh instance simulates the next HTTP request.
    $secondSeeder = new PreviewSeeder();
    $secondSeeder->seed();

    expect(DB::table('job_batches')->count())->toBe(2)
        ->and(DB::table('job_batches')->where('id', 'preview-batch-001')->value('name'))
        ->toBe('Nightly report run');
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
