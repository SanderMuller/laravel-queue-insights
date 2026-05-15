<?php declare(strict_types=1);

namespace Workbench\App\Support;

use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use SanderMuller\QueueInsights\Enums\AlertSeverity;
use SanderMuller\QueueInsights\Enums\CaptureMode;
use SanderMuller\QueueInsights\Support\CanonicalQueueKey;
use SanderMuller\QueueInsights\Support\ChainLineageStore;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\ParentClassResolver;
use SanderMuller\QueueInsights\Support\RedisEval;
use Workbench\App\Http\Middleware\SeedPreviewState;

/**
 * Hydrates Redis (and the failed_jobs DB table) with the demo state the
 * preview dashboard renders against. Runs the SAME read paths the
 * production dashboard does — `live:depth`, `pending-zset`, `completed`
 * stream, etc — so the workbench preview is a faithful end-to-end
 * exercise of the package, not a hand-built view-data fixture.
 *
 * Idempotent: every call flushes the prefixed keyspace first and re-seeds
 * from scratch so refreshes show stable state.
 */
final class PreviewSeeder
{
    /**
     * Per-request guard so a single render doesn't seed twice (livewire
     * polls re-hit the route).
     */
    private bool $seeded = false;

    public function seed(): void
    {
        if ($this->seeded) {
            return;
        }

        // Tests resolve PreviewSeeder directly, without booting
        // WorkbenchServiceProvider — call applyConfig() here as a fallback
        // so the seed has the right key_prefix / snapshots etc. The
        // workbench HTTP path sets the same config in the provider's
        // boot() so it survives Livewire's polling request (which lands
        // on `/livewire/update`, NOT the seeded `/` middleware).
        self::applyConfig();
        $this->resetState();

        $now = Date::now();
        $redis = $this->redis();

        $this->seedSnapshotsLive($redis, $now);
        $this->seedClassesAndCounters($redis, $now);
        $this->seedCompletedStream($redis, $now);
        $this->seedFailedJobs($now);
        $this->seedPending($redis, $now);
        $this->seedInFlight($redis, $now);
        $this->seedDelayed($redis, $now);
        $this->seedBatches($redis, $now);
        $this->seedWaitSamples($redis, $now);
        $this->seedDurationSamples($redis);
        $this->seedChainLineage();
        $this->seedScheduler($redis, $now);

        $this->seeded = true;
    }

    /**
     * Configure the package as if the host app was set up for the preview
     * queues. Pinned to a `qmpreview:` namespace so the workbench keyspace
     * never collides with a developer's real Redis state.
     *
     * Static so `WorkbenchServiceProvider::boot()` can apply the same
     * config on every request — including Livewire's `/livewire/update`
     * polling endpoint, which doesn't pass through the seeder middleware.
     * Without this the dashboard would read from the default `qm:` prefix
     * on polls and render every section blank.
     */
    public static function applyConfig(): void
    {
        config()->set('queue-insights.enabled', true);
        config()->set('queue-insights.key_prefix', 'qmpreview:');
        config()->set('queue-insights.snapshots', self::staticQueueDefinitions());
        config()->set('queue-insights.capture.payloads', CaptureMode::Full->value);

        // Override the bundled dashboard middleware so the workbench can
        // exercise both `/queue-insights` and `/queue-insights/{connection}`
        // without an authenticated session. WorkbenchServiceProvider's
        // permissive `Gate::before` keeps `viewQueueInsights` short-circuited
        // to true; the `auth` middleware would otherwise redirect to a
        // non-existent login route. SeedPreviewState ensures Redis is hot
        // before the dashboard reads, same as the `/` shortcut.
        config()->set('queue-insights.dashboard.middleware', [
            'web',
            SeedPreviewState::class,
        ]);

        // Permissive alerts so the dashboard renders the alert chrome with
        // realistic seeded issues. Depth threshold matches the seeded
        // `live:depth:sqs:reports = 2480` so the depth detector fires.
        config()->set('queue-insights.alerts.enabled', true);
        config()->set('queue-insights.alerts.rules.depth.thresholds', [
            [
                'connection' => 'sqs',
                'queue' => 'reports',
                'depth' => 2000,
                'severity' => AlertSeverity::Critical->value,
            ],
        ]);

        // Demo-only: pretend Slack is wired so the alerts panel surfaces a
        // realistic channel detail (`channel: #queue-alerts`). The webhook
        // URL is a placeholder — outbound notifications are not actually
        // delivered in the preview.
        config()->set('queue-insights.alerts.channels.slack.enabled', true);
        config()->set('queue-insights.alerts.channels.slack.webhook_url', 'https://hooks.slack.com/services/T0DEMO000/B0DEMO000/demoDemoDemo1234');
        config()->set('queue-insights.alerts.channels.slack.channel', '#queue-alerts');

        // Backward-chain lineage on by default — the preview demonstrates it.
        config()->set('queue-insights.chain_lineage.enabled', true);

        // Scheduler observability on — the preview seeds a realistic mix
        // of tasks + runs so the Schedule tab is fully demo-able.
        config()->set('queue-insights.scheduler.enabled', true);
        config()->set('queue-insights.scheduler.alerts.enabled', true);
        config()->set('queue-insights.scheduler.sweeper.enabled', false);  // suppress auto-registration during preview boots
        // Suppress the booted-time snapshotter rebuild — otherwise every
        // Livewire poll's app->booted fires it and overwrites the
        // seeded 6-task fixture with the workbench's actual schedule
        // (just the auto-registered `queue-insights:snapshot`).
        config()->set('queue-insights.scheduler.snapshot_rebuild', false);

        // Silenced-jobs feature dogfood — `PingThirdPartyVendor` is seeded
        // with a 45% failure rate (vs the next-noisiest class at 18%) so
        // an operator who didn't silence it would page on every snapshot
        // tick. The class still shows up in the class rows table with a
        // muted `silenced` badge, its failures stay out of the Failed
        // list by default, and the throughput sparkline's failed series
        // drops the noise without dropping the processed counts.
        config()->set('queue-insights.silenced', [
            'App\\Jobs\\PingThirdPartyVendor',
        ]);

        // Pin Laravel's batch repository to the same connection the seeder
        // writes to. Testbench's default `queue.batching.database` is
        // `sqlite` (file-backed), but the workbench runs on `:memory:`
        // — without this override, BatchRepository::find() reads from a
        // different connection than the one the seeder inserted into and
        // every seeded batch row reads as "aged out".
        config()->set('queue.batching.database', config('database.default'));
    }

    /**
     * @return list<array{connection: string, queue: string}>
     */
    private static function staticQueueDefinitions(): array
    {
        return [
            ['connection' => 'redis', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'high'],
            ['connection' => 'redis', 'queue' => 'mail'],
            ['connection' => 'sqs', 'queue' => 'reports'],
            ['connection' => 'redis', 'queue' => 'webhooks'],
            ['connection' => 'sqs', 'queue' => 'imports'],
        ];
    }

    /**
     * Wipe any prior preview state so refreshes show deterministic data.
     * Scopes the flush to the prefixed keyspace via SCAN+DEL — never
     * touches host-app keys.
     */
    private function resetState(): void
    {
        $redis = $this->redis();

        // KEYS + DEL inside a single Lua eval so the pattern matches the
        // raw redis keyspace (Laravel's client-side prefix layer applies
        // to discrete arguments, not to a Lua KEYS pattern, and differs
        // between phpredis and Predis). Pattern includes the
        // `database.redis.options.prefix` (e.g. `laravel-database-`)
        // because that's what's actually stored on the wire.
        $rawPrefix = (string) config('database.redis.options.prefix', '');
        RedisEval::exec(
            $redis,
            <<<'LUA'
local cursor = '0'
local total = 0
repeat
    local reply = redis.call('SCAN', cursor, 'MATCH', ARGV[1], 'COUNT', 500)
    cursor = reply[1]
    local keys = reply[2]
    if #keys > 0 then
        redis.call('DEL', unpack(keys))
        total = total + #keys
    end
until cursor == '0'
return total
LUA,
            0,
            $rawPrefix . 'qmpreview:*',
        );

        // Idempotent DDL: only create when missing. The earlier
        // drop+create combo races on shared MySQL — concurrent `/`
        // requests would interleave the DROP and the CREATE, exposing
        // brief windows where another connection sees `table not found`.
        // For SQLite (local dev) this is also faster: no schema thrash
        // on every page refresh.
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('uuid')->unique();
                $t->text('connection');
                $t->text('queue');
                $t->longText('payload');
                $t->longText('exception');
                $t->timestamp('failed_at')->useCurrent();
            });
        }

        // Laravel's BatchRepository (the source of truth for
        // `Bus::findBatch()` that BatchReader::recentBatches reads
        // through) hydrates from this table. Without it, every seeded
        // batch row collapses to "aged out" and the Batches tab shows 0.
        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $t): void {
                $t->string('id')->primary();
                $t->string('name');
                $t->integer('total_jobs');
                $t->integer('pending_jobs');
                $t->integer('failed_jobs');
                $t->longText('failed_job_ids');
                $t->mediumText('options')->nullable();
                $t->integer('cancelled_at')->nullable();
                $t->integer('created_at');
                $t->integer('finished_at')->nullable();
            });
        }
    }

    /**
     * `live:depth:{c}:{q}`, `live:inflight:{c}:{q}`, `live:delayed:{c}:{q}`
     * — the per-queue snapshot triple the snapshot driver writes. Seeded
     * directly so the dashboard doesn't have to dispatch a real snapshot
     * tick. Special-cases the "broken" SQS queue with a `snapshot:error`
     * so the alerts surface fires.
     */
    private function seedSnapshotsLive(RedisConnection $redis, Carbon $now): void
    {
        $rows = [
            ['redis', 'default', 12, 3, 0, null, false],
            ['redis', 'high', 0, 1, 0, null, false],
            ['redis', 'mail', 450, 5, 120, null, false],
            ['sqs', 'reports', 2480, 8, 0, null, false],
            ['redis', 'webhooks', 3, 0, 0, null, true],
            ['sqs', 'imports', null, null, null, 'AccessDenied: queue not found', false],
        ];

        // Workbench-only TTL: 1h so leaving the tab open past the
        // production 90s snapshot freshness window doesn't drop the
        // depth/inflight badges. The seeder no longer re-runs on
        // Livewire polls — re-seeding state is the page-refresh path.
        $liveTtl = 3600;

        foreach ($rows as [$connection, $queue, $depth, $inflight, $delayed, $error, $stale]) {
            $key = $queue === '' ? 'default' : CanonicalQueueKey::from($queue);
            if ($depth !== null) {
                $redis->command('setex', [KeyPrefix::make("live:depth:{$connection}:{$key}"), $liveTtl, (string) $depth]);
            }

            if ($inflight !== null) {
                $redis->command('setex', [KeyPrefix::make("live:inflight:{$connection}:{$key}"), $liveTtl, (string) $inflight]);
            }

            if ($delayed !== null) {
                $redis->command('setex', [KeyPrefix::make("live:delayed:{$connection}:{$key}"), $liveTtl, (string) $delayed]);
            }

            if (is_string($error)) {
                $redis->command('setex', [
                    KeyPrefix::make("snapshot:error:{$connection}:{$key}"),
                    600,
                    $error,
                ]);
            }

            // Historical depth zset — what `lastSnapshotAt()` reads to decide
            // freshness. Without an entry the dashboard treats the queue as
            // stale and the entire fleet lights up red. Skip for the queues
            // we're intentionally modelling as broken (error path) or stale
            // (no recent snapshot).
            if ($depth !== null && ! $stale) {
                $ts = $now->getTimestamp();
                $redis->command('zadd', [
                    KeyPrefix::make("depth:{$connection}:{$key}"),
                    $ts,
                    (string) $ts,
                ]);
                $redis->command('expire', [KeyPrefix::make("depth:{$connection}:{$key}"), 86400]);
            }

            // backlog growth samples (used by the optional backlog_growing
            // alert; only the depth=450 mail queue gets points so the
            // alert can be opt-in toggled by the operator).
            if ($connection === 'redis' && $queue === 'mail' && is_int($depth)) {
                for ($i = 9; $i >= 0; --$i) {
                    $ts = $now->copy()->subSeconds($i * 60)->getTimestamp();
                    $sampleDepth = $depth - ($i * 30);
                    $redis->command('zadd', [
                        KeyPrefix::make("samples:depth:{$connection}:{$key}"),
                        $ts,
                        "{$ts}:{$sampleDepth}",
                    ]);
                }

                $redis->command('expire', [KeyPrefix::make("samples:depth:{$connection}:{$key}"), 7200]);
            }

            // Stale flag mimics "snapshot ran a while ago" — the dashboard
            // shows a small "stale" badge per queue. We model it by keeping
            // the value but expiring soon (workbench polls fast).
            if ($stale) {
                $redis->command('expire', [KeyPrefix::make("live:depth:{$connection}:{$key}"), 5]);
            }
        }
    }

    private function seedClassesAndCounters(RedisConnection $redis, Carbon $now): void
    {
        // class → primary connection. Mirrors the connection used in the
        // completed-stream / failed_jobs / pending seeds so the dashboard
        // sums consistently across surfaces. Without per-connection
        // counters (`processed:{class}:{conn}:{bucket}`) and the matching
        // `classes:{conn}` roster, the connection-scoped throughput chart
        // reads zero everywhere — `RecordJobProcessed` writes both keys
        // in production, the seeder must mirror that contract.
        $classConnections = [
            'App\\Jobs\\SendWelcomeEmail' => 'redis',
            'App\\Jobs\\GenerateReport' => 'sqs',
            'App\\Jobs\\ProcessImport' => 'redis',
            'App\\Jobs\\SyncStripeCustomer' => 'redis',
            'App\\Jobs\\NotifyImportFinished' => 'redis',
            'App\\Jobs\\IndexImportArtifacts' => 'redis',
            'App\\Jobs\\WeeklyDigest' => 'redis',
            'App\\Jobs\\AuditCustomerSync' => 'redis',
            'App\\Jobs\\GenerateInvoicePdf' => 'redis',
            // Listed in `queue-insights.silenced` — high failure weight
            // below makes the silenced-vs-not-silenced delta visible on
            // the throughput sparkline and headline failed-tile.
            'App\\Jobs\\PingThirdPartyVendor' => 'redis',
        ];

        foreach ($classConnections as $class => $connection) {
            $redis->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), $class]);
            $redis->command('zadd', [KeyPrefix::make("classes:{$connection}"), $now->getTimestamp(), $class]);
            $redis->command('setex', [KeyPrefix::make("last_run:{$class}"), 2592000, $now->toIso8601String()]);

            // Per-hour throughput across the full 24h window the
            // dashboard's bar chart renders. Varies by hour-of-day with a
            // sine-shaped business-hours peak so the chart shows a real
            // diurnal pattern instead of a single tall bar at "now".
            $base = 30 + (abs(crc32($class)) % 80);
            // PingThirdPartyVendor stays loud — it's silenced, so the
            // failure_rate detector short-circuits and the noise drives the
            // silenced-vs-not-silenced delta on the throughput sparkline.
            // The other classes stay below the 10% threshold so the demo
            // shows realistic-but-not-paging failure traffic.
            $failureWeight = match ($class) {
                'App\\Jobs\\PingThirdPartyVendor' => 0.45,
                'App\\Jobs\\NotifyImportFinished' => 0.06,
                'App\\Jobs\\GenerateInvoicePdf' => 0.05,
                default => 0.02,
            };
            for ($i = 23; $i >= 0; --$i) {
                $hour = $now->copy()->utc()->subHours($i)->startOfHour();
                $bucket = $hour->format('YmdH');

                // Diurnal scaler: 0.3 at night → 1.0 at midday, plus
                // class-specific phase offset so different classes peak
                // at slightly different hours.
                $phase = (abs(crc32($class)) % 6) / 6.0; // 0..1
                $hourOfDay = ((int) $hour->format('G') + ($phase * 24)) % 24;
                $diurnal = 0.3 + 0.7 * (sin(($hourOfDay - 6) / 24 * 2 * M_PI) + 1) / 2;
                $processed = max(0, (int) round($base * $diurnal) + ($i % 5));
                $failed = (int) round($processed * $failureWeight) + ($i % 7 === 0 ? 1 : 0);

                $redis->command('setex', [
                    KeyPrefix::make("processed:{$class}:{$bucket}"),
                    7 * 86400,
                    (string) $processed,
                ]);
                $redis->command('setex', [
                    KeyPrefix::make("processed:{$class}:{$connection}:{$bucket}"),
                    7 * 86400,
                    (string) $processed,
                ]);
                if ($failed > 0) {
                    $redis->command('setex', [
                        KeyPrefix::make("failed:{$class}:{$bucket}"),
                        30 * 86400,
                        (string) $failed,
                    ]);
                    $redis->command('setex', [
                        KeyPrefix::make("failed:{$class}:{$connection}:{$bucket}"),
                        30 * 86400,
                        (string) $failed,
                    ]);
                }
            }
        }
    }

    /**
     * Per-class duration hashes + p95 sample windows. The dashboard's
     * job-classes panel reads these to render `avg duration` and `p95`.
     */
    private function seedDurationSamples(RedisConnection $redis): void
    {
        $samples = [
            'App\\Jobs\\SendWelcomeEmail' => [120, 145, 180, 210, 295, 342, 380],
            'App\\Jobs\\GenerateReport' => [4200, 5100, 8400, 12000, 18420, 22000],
            'App\\Jobs\\ProcessImport' => [800, 950, 1100, 1240, 1500, 1800, 2400],
            'App\\Jobs\\SyncStripeCustomer' => [310, 420, 480, 520, 600, 720],
            'App\\Jobs\\NotifyImportFinished' => [80, 95, 110, 130, 150],
            'App\\Jobs\\IndexImportArtifacts' => [1500, 1800, 2100, 2400, 3200],
            'App\\Jobs\\WeeklyDigest' => [9000, 11000, 12500, 15000],
        ];

        foreach ($samples as $class => $durations) {
            $hashKey = KeyPrefix::make("duration:{$class}");
            $sampleKey = KeyPrefix::make("duration:samples:{$class}");
            $count = count($durations);
            $sum = (float) array_sum($durations);
            $max = (string) max($durations);

            $redis->command('hset', [$hashKey, 'count', (string) $count]);
            $redis->command('hset', [$hashKey, 'sum_ms', (string) $sum]);
            $redis->command('hset', [$hashKey, 'max_ms', $max]);
            $redis->command('expire', [$hashKey, 2592000]);

            foreach ($durations as $ms) {
                $redis->command('rpush', [$sampleKey, (string) $ms]);
            }

            $redis->command('expire', [$sampleKey, 2592000]);
        }
    }

    /**
     * Completed-stream entries — the recent-completed list. Includes a
     * three-link backward-chain example so the `↰ From` block renders:
     *
     *   ProcessImport (root, has forward chain)
     *     └─ NotifyImportFinished (parent_uuid → ProcessImport)
     *         └─ IndexImportArtifacts (parent_uuid → NotifyImportFinished)
     *
     * Each link gets a real UUID + a `qi:class:{uuid}` index so the
     * lineage UI can hydrate `parent_class`.
     */
    private function seedCompletedStream(RedisConnection $redis, Carbon $now): void
    {
        $globalKey = KeyPrefix::make('completed');

        $importChainNext = json_encode([
            ['class' => 'App\\Jobs\\NotifyImportFinished', 'connection' => 'redis', 'queue' => 'mail'],
            ['class' => 'App\\Jobs\\IndexImportArtifacts', 'connection' => 'redis', 'queue' => 'default'],
        ], JSON_UNESCAPED_SLASHES);
        $stripeChainNext = json_encode([
            ['class' => 'App\\Jobs\\AuditCustomerSync', 'connection' => 'redis', 'queue' => 'default'],
        ], JSON_UNESCAPED_SLASHES);

        // ProcessImport → NotifyImportFinished → IndexImportArtifacts chain.
        $rootUuid = 'preview-uuid-process-import';
        $midUuid = 'preview-uuid-notify-import-finished';
        $tailUuid = 'preview-uuid-index-import-artifacts';

        $rows = [
            // Most recent first — XADD with explicit ms ids the modal can open.
            [
                'class' => 'App\\Jobs\\SendWelcomeEmail', 'connection' => 'redis', 'queue' => 'default',
                'duration_ms' => '342', 'attempts' => '1', 'uuid' => 'preview-uuid-welcome-1',
                'processed_at' => $now->copy()->subSeconds(20)->toIso8601String(),
            ],
            [
                'class' => 'App\\Jobs\\GenerateReport', 'connection' => 'sqs', 'queue' => 'reports',
                'duration_ms' => '18420', 'attempts' => '1', 'uuid' => 'preview-uuid-generate-report',
                'processed_at' => $now->copy()->subMinute()->toIso8601String(),
            ],
            [
                // Last link — has a parent_uuid pointing at the mid link.
                'class' => 'App\\Jobs\\IndexImportArtifacts', 'connection' => 'redis', 'queue' => 'default',
                'duration_ms' => '2400', 'attempts' => '1', 'uuid' => $tailUuid,
                'processed_at' => $now->copy()->subMinutes(2)->subSeconds(10)->toIso8601String(),
                'parent_uuid' => $midUuid,
            ],
            [
                // Mid link — has parent (root) AND a forward-chain tail (just IndexImportArtifacts left).
                'class' => 'App\\Jobs\\NotifyImportFinished', 'connection' => 'redis', 'queue' => 'mail',
                'duration_ms' => '130', 'attempts' => '1', 'uuid' => $midUuid,
                'processed_at' => $now->copy()->subMinutes(2)->subSeconds(20)->toIso8601String(),
                'parent_uuid' => $rootUuid,
                'chain' => json_encode([
                    ['class' => 'App\\Jobs\\IndexImportArtifacts', 'connection' => 'redis', 'queue' => 'default'],
                ], JSON_UNESCAPED_SLASHES),
            ],
            [
                // Root — has a full forward chain, NO parent.
                'class' => 'App\\Jobs\\ProcessImport', 'connection' => 'redis', 'queue' => 'mail',
                'duration_ms' => '1240', 'attempts' => '2', 'uuid' => $rootUuid,
                'processed_at' => $now->copy()->subMinutes(2)->toIso8601String(),
                'chain' => $importChainNext,
            ],
            [
                'class' => 'App\\Jobs\\SyncStripeCustomer', 'connection' => 'redis', 'queue' => 'default',
                'duration_ms' => '520', 'attempts' => '1', 'uuid' => 'preview-uuid-stripe-sync',
                'processed_at' => $now->copy()->subMinutes(3)->toIso8601String(),
                'chain' => $stripeChainNext,
            ],
            [
                'class' => 'App\\Jobs\\SendWelcomeEmail', 'connection' => 'redis', 'queue' => 'default',
                'duration_ms' => '295', 'attempts' => '1', 'uuid' => 'preview-uuid-welcome-2',
                'processed_at' => $now->copy()->subMinutes(5)->toIso8601String(),
            ],
        ];

        // Filler rows so the completed list has multi-page content. No
        // chain / lineage on these so they don't pollute the demo flow.
        // XADDed FIRST so they get OLDER stream-ids than the interesting
        // rows below — XADD `*` ids monotonically increase with wall clock,
        // and the dashboard sorts by stream-id (XREVRANGE), not by the
        // `processed_at` field on the entry. Without this ordering the
        // 30 fillers occupy pages 1-3 and every interesting row (chain
        // root, mid, tail; batch members; varied classes) lands on page 4.
        // Iterate oldest-first so the newest filler still sorts below the
        // interesting rows that follow.
        for ($i = 30; $i >= 1; --$i) {
            $cls = ['App\\Jobs\\SendWelcomeEmail', 'App\\Jobs\\GenerateReport', 'App\\Jobs\\AuditCustomerSync'][$i % 3];
            $row = [
                'class' => $cls,
                'connection' => 'redis',
                'queue' => 'default',
                'duration_ms' => (string) (200 + (($i * 37) % 4500)),
                'attempts' => $i % 13 === 0 ? '2' : '1',
                'uuid' => "preview-filler-{$i}",
                'processed_at' => $now->copy()->subMinutes(6 + $i)->toIso8601String(),
            ];
            $this->xadd($redis, $globalKey, $row);
            $perClass = KeyPrefix::make("completed:{$cls}");
            unset($row['class']);
            $this->xadd($redis, $perClass, $row);
        }

        // Silenced-class success traffic — `PingThirdPartyVendor` is on the
        // silenced list. Most of its runs succeed; only a small percentage
        // fail (a single seeded failure lives in `seedFailedJobs`). Without
        // the success entries the Silenced tab's Completed roster reads
        // empty even though the failure list shows activity, which
        // misrepresents the "noisy-but-mostly-OK" shape silencing is
        // designed for. 50 rows so the silenced-pane pagination (default
        // 10 per page) renders 5 pages and operators can demonstrate the
        // pager controls. XADDed after the fillers but before the
        // interesting-rows block so they don't dominate the unfiltered
        // Completed pane's page 1.
        for ($i = 50; $i >= 1; --$i) {
            $row = [
                'class' => 'App\\Jobs\\PingThirdPartyVendor',
                'connection' => 'redis',
                'queue' => 'webhooks',
                'duration_ms' => (string) (450 + (($i * 53) % 1800)),
                'attempts' => $i % 8 === 0 ? '2' : '1',
                'uuid' => "preview-silenced-completed-{$i}",
                'processed_at' => $now->copy()->subMinutes(7 + ($i * 2))->toIso8601String(),
            ];
            $streamId = $this->xadd($redis, $globalKey, $row);
            $perClass = KeyPrefix::make('completed:App\\Jobs\\PingThirdPartyVendor');
            $perRow = $row;
            unset($perRow['class']);
            $this->xadd($redis, $perClass, $perRow);

            if ($streamId !== null) {
                $redis->command('setex', [
                    KeyPrefix::make("uuid-completed:{$row['uuid']}"),
                    604800,
                    $streamId,
                ]);
            }
        }

        foreach (array_reverse($rows) as $row) {
            // Enrich the row with `payload_*` fields the details-modal's
            // Section B (job config: displayName / maxTries / timeout /
            // backoff) and Section C (raw body) render. Without these,
            // opening a parent's modal via the chain `↰ From`
            // click-through shows the chrome but every payload card is
            // empty — production hosts have these populated by
            // `RecordJobProcessed` when capture.payloads = full.
            $row = $this->enrichWithPayloadFields($row);

            $streamId = $this->xadd($redis, $globalKey, $row);
            $perClass = KeyPrefix::make("completed:{$row['class']}");
            $perRow = $row;
            unset($perRow['class']);
            $this->xadd($redis, $perClass, $perRow);

            // Index uuid → stream-id so the chain-lineage `↰ From`
            // click-through (UuidResolver) can route the modal open.
            // Production listeners write this on every JobProcessed when
            // chain_lineage OR batches are enabled; the seeder mirrors
            // that behaviour for the demo data.
            if ($streamId !== null && isset($row['uuid']) && $row['uuid'] !== '') {
                $redis->command('setex', [
                    KeyPrefix::make("uuid-completed:{$row['uuid']}"),
                    604800,
                    $streamId,
                ]);
            }
        }

        // qi:class:{uuid} — the chain-lineage class index. Hydrates
        // `parent_class` on the modal `↰ From` row + the markdown export.
        foreach ([
            $rootUuid => 'App\\Jobs\\ProcessImport',
            $midUuid => 'App\\Jobs\\NotifyImportFinished',
            $tailUuid => 'App\\Jobs\\IndexImportArtifacts',
            'preview-uuid-failed-child' => 'App\\Jobs\\NotifyImportFinished',
        ] as $uuid => $class) {
            $redis->command('setex', [ParentClassResolver::classKey($uuid), 604800, $class]);
        }
    }

    /**
     * Drive the chain lineage's interim `qi:lineage:{child-uuid}` hash
     * for the failed-row backward link. The completed-row backward link
     * is already represented via `parent_uuid` on the stream entry; the
     * failed flow needs the side-channel because failed_jobs has no
     * such column.
     */
    private function seedChainLineage(): void
    {
        $store = new ChainLineageStore();
        // The failed-job in seedFailedJobs uses uuid `preview-uuid-failed-child`
        // and was dispatched as a chained child of ProcessImport.
        $store->writeLineage('preview-uuid-failed-child', 'preview-uuid-process-import', 604800);
    }

    /**
     * failed_jobs DB rows — the dashboard reads these directly via DB
     * (not Redis). One of them carries a chain payload + a backward
     * lineage pointer so the failed-modal renders both `↳ Next` and
     * `↰ From` plus the `**Parent:**` markdown line.
     */
    private function seedFailedJobs(Carbon $now): void
    {
        // Per-request idempotency: clear the seed rows then re-insert. This
        // way edits to the fixture set (adding/removing rows, tweaking
        // exception text) take effect on the next refresh without operators
        // having to manually delete database.sqlite. Scoped via a `LIKE`
        // on the deterministic `preview-uuid-` prefix so any host-app rows
        // dropped into the same table are preserved.
        DB::table('failed_jobs')->where('uuid', 'like', 'preview-uuid-%')->delete();

        // Helper — builds a failed-job payload JSON that mirrors the
        // shape Laravel itself pushes (data.commandName/command, top-level
        // `illuminate:log:context` from ContextServiceProvider, tags).
        // The serialized command lets the failed-modal's
        // SerializedCommandReader extract instance properties; the
        // Context block lets the structured-payload + ValueParser path
        // show decoded request_id / tenant_id / user_id inline rather
        // than `s:N:"…"` literals.
        $previewFailedPayload = static function (string $uuid, string $class, int $attempts, int $maxTries, array $extraData = [], array $contextOverride = []): string {
            $context = $contextOverride !== [] ? $contextOverride : [
                'request_id' => serialize('preview-req-' . substr($uuid, -8)),
                'tenant_id' => serialize(random_int(10, 30)),
                'user_id' => serialize(random_int(1_000, 9_999)),
                'dispatcher' => serialize('queue-insights preview'),
            ];

            return (string) json_encode([
                'uuid' => $uuid,
                'displayName' => $class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => $maxTries,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => 60,
                'retryUntil' => null,
                'attempts' => $attempts,
                'data' => [
                    'commandName' => $class,
                    'command' => 'O:' . strlen($class) . ':"' . $class . '":0:{}',
                    ...$extraData,
                ],
                'illuminate:log:context' => [
                    'data' => $context,
                    'hidden' => [],
                ],
                'tags' => ['preview', 'failed', 'demo'],
            ], JSON_UNESCAPED_SLASHES);
        };

        $rows = [
            [
                'uuid' => 'preview-uuid-failed-report',
                'connection' => 'sqs',
                'queue' => 'reports',
                'payload' => $previewFailedPayload(
                    'preview-uuid-failed-report',
                    'App\\Jobs\\GenerateReport',
                    3,
                    3,
                    extraData: ['batchId' => 'preview-batch-001'],
                    contextOverride: [
                        'request_id' => serialize('preview-req-report-8a3f'),
                        'tenant_id' => serialize(17),
                        'user_id' => serialize(2_481),
                        'batch_purpose' => serialize('monthly_invoice_run'),
                        'dispatcher' => serialize('queue-insights preview'),
                    ],
                ),
                'exception' => "RuntimeException: Database connection timeout\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(8)->format('Y-m-d H:i:s'),
            ],
            [
                'uuid' => 'preview-uuid-failed-welcome',
                'connection' => 'redis',
                'queue' => 'mail',
                'payload' => $previewFailedPayload(
                    'preview-uuid-failed-welcome',
                    'App\\Jobs\\SendWelcomeEmail',
                    2,
                    3,
                ),
                'exception' => "Swift_TransportException: SMTP server refused connection\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(20)->format('Y-m-d H:i:s'),
            ],
            [
                // Chained child whose backward lineage points at ProcessImport.
                'uuid' => 'preview-uuid-failed-child',
                'connection' => 'redis',
                'queue' => 'mail',
                'payload' => $previewFailedPayload(
                    'preview-uuid-failed-child',
                    'App\\Jobs\\NotifyImportFinished',
                    1,
                    1,
                    contextOverride: [
                        'request_id' => serialize('preview-req-import-9c11'),
                        'tenant_id' => serialize(22),
                        'user_id' => serialize(7_204),
                        'parent_job' => serialize('App\\Jobs\\ProcessImport'),
                        'csv_row_count' => serialize(481),
                        'dispatcher' => serialize('queue-insights preview'),
                    ],
                ),
                'exception' => "InvalidArgumentException: Malformed CSV row 482\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subHour()->format('Y-m-d H:i:s'),
            ],
            // Silenced-class row — listed in `queue-insights.silenced` so
            // the Failed list hides it by default and the "Show silenced"
            // checkbox on the failed-pane filter form (URL `?fs=1`)
            // reveals it. Single failure paired with many seeded
            // completed-stream entries (see `seedCompletedStream`) so the
            // silenced roster shows the realistic "noisy-but-mostly-OK"
            // shape that motivates silencing in the first place.
            [
                'uuid' => 'preview-uuid-silenced-vendor-1',
                'connection' => 'redis',
                'queue' => 'webhooks',
                'payload' => $previewFailedPayload(
                    'preview-uuid-silenced-vendor-1',
                    'App\\Jobs\\PingThirdPartyVendor',
                    3,
                    3,
                    contextOverride: [
                        'request_id' => serialize('preview-req-vendor-2d4e'),
                        'vendor' => serialize('payments.example.com'),
                        'endpoint' => serialize('POST /webhooks/customer.updated'),
                        'timeout_ms' => serialize(5000),
                        'dispatcher' => serialize('queue-insights preview'),
                    ],
                ),
                'exception' => "GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 5000 ms\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(4)->format('Y-m-d H:i:s'),
            ],
        ];

        $redis = $this->redis();
        foreach ($rows as $row) {
            $id = DB::table('failed_jobs')->insertGetId($row);

            // qi:uuid-failed:{uuid} → failed_jobs.id, mirroring what
            // RecordJobFailed writes on real failures. Drives the chain-
            // lineage `↰ From` click-through to a failed parent.
            $redis->command('setex', [
                KeyPrefix::make("uuid-failed:{$row['uuid']}"),
                604800,
                (string) $id,
            ]);
        }
    }

    /**
     * Pending hashes + zsets across the configured queues. Production
     * shape — `RecordJobQueued` writes these on every `JobQueued` event
     * and the dashboard reads them via `PendingJobsReader::allPending`.
     *
     * Counts are calibrated so the headline `Pending preview` strip
     * shows 2 in-flight + 2 pending + 1 delayed (= 5 visible at the
     * `pendingPreview` cap of 5), surfacing one delayed entry above
     * the fold.
     */
    private function seedPending(RedisConnection $redis, Carbon $now): void
    {
        // Tuple shape: [uuid, class, conn, queue, queuedAt, batchId, attempts].
        $rows = [
            ['preview-pending-1', 'App\\Jobs\\SendWelcomeEmail', 'redis', 'default', $now->copy()->subSeconds(45), null, null],
            ['preview-pending-2', 'App\\Jobs\\GenerateReport', 'sqs', 'reports', $now->copy()->subMinutes(8), 'preview-batch-001', null],
            ['preview-pending-retry', 'App\\Jobs\\PingThirdPartyVendor', 'redis', 'default', $now->copy()->subSeconds(15), null, 2],
        ];

        foreach ($rows as [$uuid, $class, $connection, $queue, $queuedAt, $batchId, $attempts]) {
            $this->writePendingHash(
                $redis,
                $uuid,
                $class,
                $connection,
                $queue,
                queuedAt: $queuedAt,
                availableAt: $queuedAt,
                batchId: $batchId,
                attempts: $attempts,
            );
        }
    }

    /**
     * In-flight: pending hash + state=in_flight + inflight-zset entry per
     * queue. One row demonstrates parent_uuid hydration (the in-flight
     * modal renders `↰ From` once the partial is fed via DashboardData).
     */
    private function seedInFlight(RedisConnection $redis, Carbon $now): void
    {
        // Tuple shape: [uuid, class, conn, queue, queuedAt, startedAt, batchId, parentUuid, attempts].
        $rows = [
            [
                'preview-inflight-1', 'App\\Jobs\\ProcessImport', 'redis', 'default',
                $now->copy()->subSeconds(45), $now->copy()->subSeconds(20), null, null, null,
            ],
            [
                // Chained child running right now — `parent_uuid` set so
                // the in-flight modal demonstrates the backward link.
                'preview-inflight-2', 'App\\Jobs\\NotifyImportFinished', 'redis', 'mail',
                $now->copy()->subMinutes(3), $now->copy()->subMinutes(2), null,
                'preview-uuid-process-import', null,
            ],
            [
                'preview-inflight-retry', 'App\\Jobs\\ChargeStripeCustomer', 'redis', 'default',
                $now->copy()->subMinutes(2), $now->copy()->subSeconds(35), null, null, 3,
            ],
        ];

        foreach ($rows as [$uuid, $class, $connection, $queue, $queuedAt, $startedAt, $batchId, $parentUuid, $attempts]) {
            $this->writePendingHash(
                $redis,
                $uuid,
                $class,
                $connection,
                $queue,
                queuedAt: $queuedAt,
                availableAt: $queuedAt,
                batchId: $batchId,
                state: 'in_flight',
                startedAt: $startedAt,
                parentUuid: $parentUuid,
                indexInPendingZset: false,
                attempts: $attempts,
            );

            $queueKey = $queue === '' ? 'default' : CanonicalQueueKey::from($queue);
            $redis->command('zadd', [
                KeyPrefix::make("inflight-zset:{$connection}:{$queueKey}"),
                $startedAt->getTimestamp(),
                $uuid,
            ]);
            $redis->command('expire', [KeyPrefix::make("inflight-zset:{$connection}:{$queueKey}"), 86400]);
        }
    }

    /**
     * Delayed: same hash + zset shape as pending, but `available_at` is
     * in the future. The dashboard groups these into the Delayed tile.
     */
    private function seedDelayed(RedisConnection $redis, Carbon $now): void
    {
        $rows = [
            ['preview-delayed-1', 'App\\Jobs\\SendReminder', 'redis', 'mail', $now->copy()->subMinute(), $now->copy()->addMinutes(2), null],
            ['preview-delayed-2', 'App\\Jobs\\WeeklyDigest', 'redis', 'mail', $now->copy()->subHour(), $now->copy()->addHour(), null],
        ];

        foreach ($rows as [$uuid, $class, $connection, $queue, $queuedAt, $availableAt, $batchId]) {
            $this->writePendingHash($redis, $uuid, $class, $connection, $queue, $queuedAt, $availableAt, $batchId);
        }
    }

    /**
     * Two seeded batches — one in-progress (mixed pending/processed),
     * one finished — so the Batches section has live + closed cells.
     */
    private function seedBatches(RedisConnection $redis, Carbon $now): void
    {
        // Note: NOT short-circuiting on `job_batches` row count. `resetState`
        // flushes the Redis prefix every seed, so the Redis-side index
        // (`batches:index` zset + `batch:{id}:uuids` lists) needs to be
        // rewritten every tick — otherwise the dashboard's Batches tab
        // empties out after the first reseed even though the DB rows
        // survive. Per-batch idempotency lives in `writeBatch`.

        // Batch 1 — preview-batch-001, in-progress: 1 finished, 1 failed,
        // 1 pending, 1 in-flight-completed. Drives the "Active" badge.
        $batch1 = 'preview-batch-001';
        $uuids1 = [
            'preview-uuid-generate-report',
            'preview-uuid-failed-report',
            'preview-pending-2',
            'preview-uuid-welcome-1',
        ];
        $this->writeBatch(
            $redis,
            $batch1,
            'Nightly report run',
            $uuids1,
            createdAt: $now->copy()->subMinutes(15),
            pendingJobs: 1,
            failedJobs: 1,
            failedJobIds: ['preview-uuid-failed-report'],
            finishedAt: null,
        );

        // Batch 2 — finished. All uuids resolve to completed entries.
        $batch2 = 'preview-batch-002';
        $uuids2 = ['preview-uuid-stripe-sync', 'preview-uuid-welcome-2'];
        $this->writeBatch(
            $redis,
            $batch2,
            'Stripe customer sync',
            $uuids2,
            createdAt: $now->copy()->subHours(2),
            pendingJobs: 0,
            failedJobs: 0,
            failedJobIds: [],
            finishedAt: $now->copy()->subHour(),
        );
    }

    /**
     * @param  list<string>  $uuids
     * @param  list<string>  $failedJobIds
     */
    private function writeBatch(
        RedisConnection $redis,
        string $batchId,
        string $name,
        array $uuids,
        Carbon $createdAt,
        int $pendingJobs,
        int $failedJobs,
        array $failedJobIds,
        ?Carbon $finishedAt,
    ): void {
        $ttl = 604800;

        // Redis-side index — `BatchReader::recentBatches` uses this to
        // enumerate ids; `findBatch()` reads the DB row below.
        RedisEval::exec(
            $redis,
            "return redis.call('ZADD', KEYS[1], 'NX', ARGV[1], ARGV[2])",
            1,
            KeyPrefix::make('batches:index'),
            (string) $createdAt->getTimestamp(),
            $batchId,
        );

        $uuidsKey = KeyPrefix::make("batch:{$batchId}:uuids");
        foreach ($uuids as $uuid) {
            $redis->command('rpush', [$uuidsKey, $uuid]);
            $redis->command('setex', [KeyPrefix::make("batch:uuid:{$uuid}"), $ttl, $batchId]);
        }

        $redis->command('expire', [$uuidsKey, $ttl]);

        // DB-side row — Laravel's BatchRepository::find() reads this.
        // Without it `Bus::findBatch()` returns null and BatchReader
        // silently skips the row, leaving the Batches tab badge at 0.
        // Atomic upsert keyed on the deterministic preview id. Earlier
        // `updateOrInsert` races under wire:navigate prefetch — two
        // concurrent requests both SELECT empty, both INSERT, second
        // crashes on the unique primary key. `upsert()` compiles to a
        // single `INSERT ... ON CONFLICT DO UPDATE` statement that the
        // DB resolves atomically.
        DB::table('job_batches')->upsert(
            [[
                'id' => $batchId,
                'name' => $name,
                'total_jobs' => count($uuids),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'failed_job_ids' => json_encode($failedJobIds),
                'options' => serialize([]),
                'cancelled_at' => null,
                'created_at' => $createdAt->getTimestamp(),
                'finished_at' => $finishedAt?->getTimestamp(),
            ]],
            ['id'],
            ['name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
        );
    }

    /**
     * Per-queue rolling wait-sample zset — drives the p50/p95 columns on
     * the queues table. Seeds 30 samples each in a realistic range so
     * the percentiles render with non-trivial values.
     */
    private function seedWaitSamples(RedisConnection $redis, Carbon $now): void
    {
        $queues = [
            ['redis', 'default', range(20, 200, 6)],
            ['redis', 'high', range(8, 80, 3)],
            ['redis', 'mail', array_merge(range(400, 2000, 50), [3400])],
            ['sqs', 'reports', array_merge(range(2000, 8000, 200), [22000])],
            // webhooks needs samples too — without them the `stalled`
            // detector fires (depth ≥ 1 + zero recent wait pickups) before
            // the snapshot expires at 5s and burns a slot in the demo's
            // alerts panel.
            ['redis', 'webhooks', range(40, 400, 12)],
        ];

        foreach ($queues as [$connection, $queue, $samples]) {
            $queueKey = CanonicalQueueKey::from($queue);
            $waitKey = KeyPrefix::make("wait:{$connection}:{$queueKey}");
            $i = 0;
            foreach ($samples as $waitMs) {
                $uuid = "preview-wait-{$connection}-{$queueKey}-{$i}";
                $redis->command('setex', [KeyPrefix::make("wait:{$uuid}"), 604800, (string) $waitMs]);
                $redis->command('zadd', [$waitKey, $now->copy()->subSeconds($i)->getTimestamp(), $uuid]);
                ++$i;
            }

            $redis->command('expire', [$waitKey, 604800]);
        }
    }

    /**
     * Enrich a seeded completed-stream row with the `payload_*` fields the
     * details-modal renders in Section B (job config) and Section C (raw
     * body). Production hosts get these populated by
     * `RecordJobProcessed::writeStreams` when `capture.payloads = full`;
     * the workbench mirrors that here so the parent-modal chain
     * click-through demo shows realistic data instead of an empty
     * Section B / Section C.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function enrichWithPayloadFields(array $row): array
    {
        $class = $row['class'] ?? 'App\\Jobs\\Unknown';
        $uuid = $row['uuid'] ?? '00000000-0000-0000-0000-000000000000';

        $bodyJson = json_encode([
            'uuid' => $uuid,
            'displayName' => $class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => [60, 120],
            'timeout' => 60,
            'retryUntil' => null,
            'data' => [
                'commandName' => $class,
                'command' => 'O:' . strlen($class) . ':"' . $class . '":0:{}',
            ],
            // Top-level Context — same shape `ContextServiceProvider::boot`
            // attaches at JobQueueing time. Values are PHP-serialized
            // scalars because that's what `Context::dehydrate()` returns;
            // the dashboard's `ValueParser::decodeScalar` unwraps them
            // inline on the modal so operators see the actual values.
            'illuminate:log:context' => [
                'data' => [
                    'request_id' => serialize('preview-' . substr($uuid, 0, 8)),
                    'tenant_id' => serialize(random_int(10, 30)),
                    'user_id' => serialize(random_int(1_000, 9_999)),
                    'dispatcher' => serialize('queue-insights preview'),
                ],
                'hidden' => [],
            ],
            'tags' => ['preview', 'demo'],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $row['payload_displayName'] = $class;
        $row['payload_maxTries'] = '3';
        $row['payload_timeout'] = '60';
        $row['payload_backoff'] = '[60,120]';
        $row['payload_body'] = $bodyJson;

        return $row;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function xadd(RedisConnection $redis, string $key, array $fields): ?string
    {
        // Filter out empty fields — XADD doesn't allow empty values for some
        // fields and the modal partial guards on key presence anyway.
        $clean = [];
        foreach ($fields as $k => $v) {
            $clean[$k] = (string) $v;
        }

        $flat = [];
        foreach ($clean as $k => $v) {
            $flat[] = $k;
            $flat[] = $v;
        }

        $result = RedisEval::exec(
            $redis,
            "return redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', unpack(ARGV, 2))",
            1,
            $key,
            '10000',
            ...$flat,
        );

        return is_string($result) && $result !== '' ? $result : null;
    }

    private function writePendingHash(
        RedisConnection $redis,
        string $uuid,
        string $class,
        string $connection,
        string $queue,
        Carbon $queuedAt,
        Carbon $availableAt,
        ?string $batchId = null,
        ?string $state = null,
        ?Carbon $startedAt = null,
        ?string $parentUuid = null,
        bool $indexInPendingZset = true,
        ?int $attempts = null,
    ): void {
        $queueKey = $queue === '' ? 'default' : CanonicalQueueKey::from($queue);
        $hashKey = KeyPrefix::make("pending:{$uuid}");
        $ttl = 86400;

        $fields = [
            'connection' => $connection,
            'queue' => $queueKey,
            'class' => $class,
            'queued_at' => (string) $queuedAt->getTimestamp(),
            'available_at' => (string) $availableAt->getTimestamp(),
            'batch_id' => $batchId ?? '',
        ];
        if ($state !== null) {
            $fields['state'] = $state;
        }

        if ($startedAt instanceof Carbon) {
            $fields['started_at'] = (string) $startedAt->getTimestamp();
        }

        if ($parentUuid !== null) {
            $fields['parent_uuid'] = $parentUuid;
        }

        if ($attempts !== null) {
            $fields['attempts'] = (string) $attempts;
        }

        // Pending payload capture — production hosts get these fields
        // written by `RecordJobQueued` when `pending.capture.payloads =
        // full`. The preview mirrors that here so every preview pending
        // / in-flight row's modal renders the structured-payload section
        // (with the decoded `illuminate:log:context` tree) instead of a
        // bare metadata stub. The enricher reads `class` + `uuid` from
        // the input row and adds `payload_*` keys; PHP's `+ $fields`
        // semantics already preserve existing keys, so no tail-merge
        // needed.
        $fields = $this->enrichWithPayloadFields(['class' => $class, 'uuid' => $uuid] + $fields);

        foreach ($fields as $field => $value) {
            $redis->command('hset', [$hashKey, $field, $value]);
        }

        $redis->command('expire', [$hashKey, $ttl]);

        // In production, `RecordJobProcessing::markInFlight` ZREMs the
        // uuid from pending-zset before ZADDing it to inflight-zset, so a
        // running job is in exactly one of the two indexes. Mirror that
        // — the in-flight callers pass `indexInPendingZset: false`.
        if ($indexInPendingZset) {
            $zsetKey = KeyPrefix::make("pending-zset:{$connection}:{$queueKey}");
            $redis->command('zadd', [$zsetKey, $availableAt->getTimestamp(), $uuid]);
            $redis->command('expire', [$zsetKey, $ttl]);
        }
    }

    /**
     * Seed the scheduler subsystem with a realistic mix of tasks + runs.
     * Mirrors what `ScheduleSnapshotter` + the five `RecordScheduled*`
     * listeners + the sweeper would write in production. Drives the
     * Schedule dashboard tab, the headline tiles, the per-task
     * needs-attention/healthy split, and the queue↔schedule attribution
     * `↗ Schedule` chip.
     *
     * Tasks (with their demo-state):
     *   - SyncCustomers (every 5 min)        — healthy, p95 ~1.2s
     *   - GenerateInvoices (daily)           — healthy, longer runtime
     *   - PruneCache (every minute)          — healthy, very fast
     *   - NightlyBackup (daily, background)  — NEEDS ATTENTION (1 hung)
     *   - SyncStripeCustomers (every 10 min) — NEEDS ATTENTION (recent failed run)
     *   - closure@routes/console.php:42      — healthy unnamed closure
     */
    private function seedScheduler(RedisConnection $redis, Carbon $now): void
    {
        $tasks = $this->schedulerPreviewTasks();

        $this->seedSchedulerSnapshot($redis, $tasks, $now);
        $this->seedSchedulerAggregates($redis, $tasks, $now);
        $this->seedSchedulerCounters($redis, $tasks, $now);
        $this->seedSchedulerRuns($redis, $tasks, $now);
        $this->seedSchedulerInFlightAndHung($redis, $tasks, $now);
        $this->seedSchedulerJobAttribution($redis, $tasks, $now);
    }

    /**
     * @return list<array{
     *   key: string,
     *   description: string,
     *   command: string,
     *   expression: string,
     *   type: string,
     *   runInBackground: bool,
     *   onOneServer: bool,
     *   evenInMaintenanceMode: bool,
     *   withoutOverlapping: bool,
     *   mutexName: string,
     *   p95_ms: int,
     *   failure_rate: float,
     *   skip_rate: float,
     *   demo_failing_first_run: bool,
     *   demo_hung: bool,
     *   demo_in_flight: bool,
     *   demo_skipped_run: bool,
     *   demo_attribution_source: bool,
     *   demo_captured_output: bool,
     * }>
     */
    private function schedulerPreviewTasks(): array
    {
        // demo-state flags (right of `skip_rate`):
        //   failing | hung | in_flight | skipped_run | attribution_source | captured_output
        $defs = [
            ['App\\Console\\Commands\\SyncCustomers', '*/5 * * * *', 'command', false, false, true, 1200, 0.02, 0.0, false, false, true, false, true, true],
            ['App\\Console\\Commands\\GenerateInvoices', '0 1 * * *', 'command', false, true, true, 18400, 0.0, 0.0, false, false, false, false, false, false],
            ['App\\Console\\Commands\\PruneCache', '* * * * *', 'command', false, false, true, 95, 0.0, 0.0, false, false, false, false, false, false],
            ['App\\Console\\Commands\\NightlyBackup', '0 2 * * *', 'command', true, true, false, 240000, 0.0, 0.0, false, true, false, false, false, false],
            ['App\\Console\\Commands\\SyncStripeCustomers', '*/10 * * * *', 'command', false, false, true, 4200, 0.18, 0.05, true, false, false, false, false, false],
            ['closure@routes/console.php:42', '0 */6 * * *', 'closure', false, false, false, 380, 0.0, 0.12, false, false, false, true, false, false],
        ];

        $out = [];
        foreach ($defs as [$desc, $expr, $type, $bg, $oneServer, $woOverlap, $p95, $failRate, $skipRate, $failing, $hung, $inFlight, $skippedRun, $attribution, $capturedOutput]) {
            $mutex = 'framework/schedule-' . hash('sha256', $expr . $desc);
            $out[] = [
                'key' => hash('sha256', $mutex),
                'description' => $desc,
                'command' => $type === 'closure' ? 'closure' : "'php' 'artisan' " . $desc,
                'expression' => $expr,
                'type' => $type,
                'runInBackground' => $bg,
                'onOneServer' => $oneServer,
                'evenInMaintenanceMode' => false,
                'withoutOverlapping' => $woOverlap,
                'mutexName' => $mutex,
                'p95_ms' => $p95,
                'failure_rate' => $failRate,
                'skip_rate' => $skipRate,
                'demo_failing_first_run' => $failing,
                'demo_hung' => $hung,
                'demo_in_flight' => $inFlight,
                'demo_skipped_run' => $skippedRun,
                'demo_attribution_source' => $attribution,
                'demo_captured_output' => $capturedOutput,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function seedSchedulerSnapshot(RedisConnection $redis, array $tasks, Carbon $now): void
    {
        $tasksKey = KeyPrefix::make('sched:tasks');
        $orderKey = KeyPrefix::make('sched:tasks:order');

        $summaries = [];
        foreach ($tasks as $task) {
            $summary = [
                'description' => $task['description'],
                'command' => $task['command'],
                'expression' => $task['expression'],
                'timezone' => 'UTC',
                'runInBackground' => $task['runInBackground'],
                'onOneServer' => $task['onOneServer'],
                'evenInMaintenanceMode' => $task['evenInMaintenanceMode'],
                'withoutOverlapping' => $task['withoutOverlapping'],
                'mutexName' => $task['mutexName'],
                'type' => $task['type'],
            ];
            $summaries[$task['key']] = $summary;

            $redis->command('hset', [$tasksKey, $task['key'], (string) json_encode($summary)]);
            $redis->command('rpush', [$orderKey, $task['key']]);
        }

        $redis->command('set', [
            KeyPrefix::make('sched:snapshot:hash'),
            hash('sha256', (string) json_encode($summaries)),
        ]);
        $redis->command('set', [
            KeyPrefix::make('sched:snapshot:at'),
            (string) $now->getTimestampMs(),
        ]);
    }

    /**
     * 24h of hourly aggregate buckets + runtime samples. Drives the
     * sparkline + the per-task p95.
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    private function seedSchedulerAggregates(RedisConnection $redis, array $tasks, Carbon $now): void
    {
        $aggTtl = 192 * 3600;

        foreach ($tasks as $task) {
            $key = $task['key'];
            // Approximate fires-per-hour from the cron expression. Good
            // enough for a demo — exact value isn't important.
            $firesPerHour = match ($task['expression']) {
                '* * * * *' => 60,
                '*/5 * * * *' => 12,
                '*/10 * * * *' => 6,
                '0 */6 * * *' => 1,
                '0 1 * * *', '0 2 * * *' => 1,
                default => 4,
            };

            for ($i = 23; $i >= 0; --$i) {
                $hour = $now->copy()->utc()->subHours($i)->startOfHour();
                $bucket = $hour->format('YmdH');
                $aggKey = KeyPrefix::make("sched:agg:{$key}:{$bucket}");
                $samplesKey = KeyPrefix::make("sched:samples:{$key}:{$bucket}");

                $diurnal = 0.4 + 0.6 * (sin(((int) $hour->format('G') - 6) / 24 * 2 * M_PI) + 1) / 2;
                $totalFires = max(1, (int) round($firesPerHour * $diurnal));
                $failed = (int) round($totalFires * $task['failure_rate']);
                $success = max(0, $totalFires - $failed);

                $p95 = (int) $task['p95_ms'];
                $runtimeSum = $totalFires * (int) round($p95 * 0.65);

                $redis->command('hset', [$aggKey, 'success_count', (string) $success]);
                if ($failed > 0) {
                    $redis->command('hset', [$aggKey, 'failed_count', (string) $failed]);
                }

                $redis->command('hset', [$aggKey, 'runtime_sum_ms', (string) $runtimeSum]);
                $redis->command('expire', [$aggKey, $aggTtl]);

                // Runtime samples — use a small spread around p95 so the
                // computed percentile lands close to the configured value.
                for ($j = 0; $j < min(20, $totalFires); ++$j) {
                    $variance = ($j - 10) * 30;
                    $redis->command('rpush', [$samplesKey, (string) max(10, $p95 + $variance)]);
                }

                $redis->command('expire', [$samplesKey, $aggTtl]);
            }
        }
    }

    /**
     * Lifetime counters (no TTL). Drives last-run/last-failed tooltips +
     * needs-attention/healthy split.
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    private function seedSchedulerCounters(RedisConnection $redis, array $tasks, Carbon $now): void
    {
        foreach ($tasks as $task) {
            $key = $task['key'];
            $countersKey = KeyPrefix::make("sched:counters:{$key}");

            $totalRuns = 1200 + abs(crc32($key)) % 800;
            $totalFailed = (int) round($totalRuns * $task['failure_rate']);
            $totalSkipped = (int) round($totalRuns * $task['skip_rate']);

            $redis->command('hset', [$countersKey, 'total_runs', (string) $totalRuns]);
            $redis->command('hset', [$countersKey, 'total_failed', (string) $totalFailed]);
            $redis->command('hset', [$countersKey, 'total_skipped', (string) $totalSkipped]);
            $redis->command('hset', [$countersKey, 'last_run_at', (string) $now->copy()->subSeconds((abs(crc32($key))) % 600)->getTimestampMs()]);
            if ($totalFailed > 0) {
                $redis->command('hset', [$countersKey, 'last_failed_at', (string) $now->copy()->subMinutes(8)->getTimestampMs()]);
            }

            $redis->command('hset', [$countersKey, 'last_success_at', (string) $now->copy()->subMinutes(2)->getTimestampMs()]);

            // The NightlyBackup task is staged with one hung run below.
            if ($task['description'] === 'App\\Console\\Commands\\NightlyBackup') {
                $redis->command('hset', [$countersKey, 'total_hung', '1']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function seedSchedulerRuns(RedisConnection $redis, array $tasks, Carbon $now): void
    {
        $allKey = KeyPrefix::make('sched:runs:all');
        $runTtl = 604800;
        $hosts = ['web-01', 'web-02'];
        $runsAdded = 0;

        foreach ($tasks as $task) {
            $key = $task['key'];
            $runsKey = KeyPrefix::make("sched:runs:{$key}");
            $isFailing = $task['demo_failing_first_run'];

            // 5 historical runs per task, spread across the last 6 hours.
            for ($i = 0; $i < 5; ++$i) {
                $startedAt = $now->copy()->subMinutes(($i + 1) * 23 + (abs(crc32($key))) % 7);
                $finishedAt = $startedAt->copy()->addMilliseconds((int) round((int) $task['p95_ms'] * 0.7));
                $runId = "preview-run-{$key}-{$i}";

                $statusForcedFail = ($isFailing && $i === 0);
                $status = $statusForcedFail ? 'failed' : 'success';
                $exitCode = $statusForcedFail ? 1 : 0;

                $runHashKey = KeyPrefix::make("sched:run:{$key}:{$runId}");
                $redis->command('hset', [$runHashKey, 'started_at', (string) $startedAt->getTimestampMs()]);
                $redis->command('hset', [$runHashKey, 'finished_at', (string) $finishedAt->getTimestampMs()]);
                $redis->command('hset', [$runHashKey, 'runtime_ms', (string) ((int) round((int) $task['p95_ms'] * 0.7))]);
                $redis->command('hset', [$runHashKey, 'exit_code', (string) $exitCode]);
                $redis->command('hset', [$runHashKey, 'status', $status]);
                $redis->command('hset', [$runHashKey, 'host_id', $hosts[$runsAdded % 2]]);
                $redis->command('hset', [$runHashKey, 'is_background', $task['runInBackground'] ? '1' : '0']);
                if ($statusForcedFail) {
                    $exception = json_encode([
                        'class' => ConnectException::class,
                        'message' => 'cURL error 28: Operation timed out after 5000 ms',
                        'file' => '/preview/Stack.php',
                        'line' => 42,
                        'trace_tail' => "#0 /preview/Stack.php(42): preview()\n#1 /preview/Worker.php(118): App\\Console\\Commands\\SyncStripeCustomers::handle()\n#2 {main}",
                    ]);
                    $redis->command('hset', [$runHashKey, 'exception', (string) $exception]);
                }

                // Attach a captured stdout blob to the most recent run of the
                // designated demo task so the per-run modal exercises its
                // output viewer in the live preview.
                if ($task['demo_captured_output'] && $i === 0 && ! $statusForcedFail) {
                    $output = "Synced 4 customers in 312ms\nUpdated 2 stale records\nDispatched 4 follow-up jobs\n";
                    $redis->command('hset', [$runHashKey, 'output', $output]);
                }

                $redis->command('expire', [$runHashKey, $runTtl]);

                $redis->command('zadd', [$runsKey, $startedAt->getTimestampMs(), $runId]);
                $redis->command('zadd', [$allKey, $startedAt->getTimestampMs(), "{$key}:{$runId}"]);
                ++$runsAdded;
            }

            $redis->command('expire', [$runsKey, $runTtl]);

            // Tasks flagged with `demo_skipped_run` get one synthetic
            // skipped run so the dashboard's skip-reason path has data.
            if ($task['demo_skipped_run']) {
                $skippedAt = $now->copy()->subHours(7);
                $skippedRunId = "preview-run-{$key}-skipped";
                $skippedHashKey = KeyPrefix::make("sched:run:{$key}:{$skippedRunId}");
                $redis->command('hset', [$skippedHashKey, 'started_at', (string) $skippedAt->getTimestampMs()]);
                $redis->command('hset', [$skippedHashKey, 'finished_at', (string) $skippedAt->getTimestampMs()]);
                $redis->command('hset', [$skippedHashKey, 'status', 'skipped']);
                $redis->command('hset', [$skippedHashKey, 'skip_reason', 'between']);
                $redis->command('hset', [$skippedHashKey, 'host_id', 'web-01']);
                $redis->command('expire', [$skippedHashKey, $runTtl]);
                $redis->command('zadd', [$runsKey, $skippedAt->getTimestampMs(), $skippedRunId]);
                $redis->command('zadd', [$allKey, $skippedAt->getTimestampMs(), "{$key}:{$skippedRunId}"]);
            }
        }
    }

    /**
     * One hung task (NightlyBackup) + one in-flight run (SyncCustomers)
     * so the dashboard's running-tasks chrome and needs-attention split
     * both render real cells.
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    private function seedSchedulerInFlightAndHung(RedisConnection $redis, array $tasks, Carbon $now): void
    {
        $indexKey = KeyPrefix::make('sched:running-index');

        foreach ($tasks as $task) {
            $key = $task['key'];

            if ($task['demo_hung']) {
                // Hung run — running pointer present, expected_finish in
                // past, status=hung on the run hash.
                $runId = "preview-run-{$key}-hung";
                $startedAt = $now->copy()->subHours(3);
                $expectedFinishAt = $now->copy()->subHour();

                $runHashKey = KeyPrefix::make("sched:run:{$key}:{$runId}");
                $redis->command('hset', [$runHashKey, 'started_at', (string) $startedAt->getTimestampMs()]);
                $redis->command('hset', [$runHashKey, 'status', 'hung']);
                $redis->command('hset', [$runHashKey, 'host_id', 'web-02']);
                $redis->command('hset', [$runHashKey, 'is_background', '1']);
                $redis->command('expire', [$runHashKey, 604800]);

                $runsKey = KeyPrefix::make("sched:runs:{$key}");
                $redis->command('zadd', [$runsKey, $startedAt->getTimestampMs(), $runId]);
                $redis->command('zadd', [
                    KeyPrefix::make('sched:runs:all'),
                    $startedAt->getTimestampMs(),
                    "{$key}:{$runId}",
                ]);

                $runningKey = KeyPrefix::make("sched:running:{$key}");
                $redis->command('hset', [$runningKey, 'run_id', $runId]);
                $redis->command('hset', [$runningKey, 'started_at_ms', (string) $startedAt->getTimestampMs()]);
                $redis->command('hset', [$runningKey, 'expected_finish_at_ms', (string) $expectedFinishAt->getTimestampMs()]);
                $redis->command('zadd', [$indexKey, $expectedFinishAt->getTimestampMs(), $key]);
            }

            if ($task['demo_in_flight']) {
                // Active in-flight run — `status=starting`, expected
                // finish in the future. Surfaces as a live row.
                $runId = "preview-run-{$key}-running";
                $startedAt = $now->copy()->subSeconds(20);
                $expectedFinishAt = $now->copy()->addSeconds(40);

                $runHashKey = KeyPrefix::make("sched:run:{$key}:{$runId}");
                $redis->command('hset', [$runHashKey, 'started_at', (string) $startedAt->getTimestampMs()]);
                $redis->command('hset', [$runHashKey, 'status', 'starting']);
                $redis->command('hset', [$runHashKey, 'host_id', 'web-01']);
                $redis->command('hset', [$runHashKey, 'is_background', '0']);
                $redis->command('expire', [$runHashKey, 604800]);

                $runsKey = KeyPrefix::make("sched:runs:{$key}");
                $redis->command('zadd', [$runsKey, $startedAt->getTimestampMs(), $runId]);
                $redis->command('zadd', [
                    KeyPrefix::make('sched:runs:all'),
                    $startedAt->getTimestampMs(),
                    "{$key}:{$runId}",
                ]);

                $runningKey = KeyPrefix::make("sched:running:{$key}");
                $redis->command('hset', [$runningKey, 'run_id', $runId]);
                $redis->command('hset', [$runningKey, 'started_at_ms', (string) $startedAt->getTimestampMs()]);
                $redis->command('hset', [$runningKey, 'expected_finish_at_ms', (string) $expectedFinishAt->getTimestampMs()]);
                $redis->command('zadd', [$indexKey, $expectedFinishAt->getTimestampMs(), $key]);
            }
        }
    }

    /**
     * Demo the queue↔schedule attribution surface: pick the most recent
     * SyncCustomers run, attach two seeded pending uuids to its
     * `qi:sched:run-jobs:{runId}` zset, and stamp the schedule frame
     * onto those pending hashes.
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    private function seedSchedulerJobAttribution(RedisConnection $redis, array $tasks, Carbon $now): void
    {
        $sourceKey = null;
        foreach ($tasks as $task) {
            if ($task['demo_attribution_source']) {
                $sourceKey = $task['key'];

                break;
            }
        }

        if ($sourceKey === null) {
            return;
        }

        $runId = "preview-run-{$sourceKey}-0";
        $jobsKey = KeyPrefix::make("sched:run-jobs:{$runId}");

        // Attribute the existing seeded pending uuids to this run so the
        // dashboard can demonstrate "jobs dispatched during this run".
        foreach (['preview-pending-1', 'preview-inflight-1'] as $uuid) {
            $redis->command('zadd', [$jobsKey, $now->getTimestamp(), $uuid]);

            $hashKey = KeyPrefix::make("pending:{$uuid}");
            $redis->command('hset', [$hashKey, 'schedule_task_key', $sourceKey]);
            $redis->command('hset', [$hashKey, 'schedule_run_id', $runId]);
        }

        $redis->command('expire', [$jobsKey, 604800]);
    }

    private function redis(): RedisConnection
    {
        return Redis::connection(config()->get('queue-insights.redis_connection', 'default'));
    }
}
