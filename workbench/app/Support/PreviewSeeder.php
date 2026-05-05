<?php declare(strict_types=1);

namespace Workbench\App\Support;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection as RedisConnection;
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

        $now = Carbon::now();
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
            // backlog growth samples (used by the optional backlog_growing
            // alert; only the depth=450 mail queue gets points so the
            // alert can be opt-in toggled by the operator).
            if ($connection === 'redis' && $queue === 'mail' && is_int($depth)) {
                for ($i = 9; $i >= 0; $i--) {
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
        $classes = [
            'App\\Jobs\\SendWelcomeEmail',
            'App\\Jobs\\GenerateReport',
            'App\\Jobs\\ProcessImport',
            'App\\Jobs\\SyncStripeCustomer',
            'App\\Jobs\\NotifyImportFinished',
            'App\\Jobs\\IndexImportArtifacts',
            'App\\Jobs\\WeeklyDigest',
            'App\\Jobs\\AuditCustomerSync',
            'App\\Jobs\\GenerateInvoicePdf',
            // Listed in `queue-insights.silenced` — high failure weight
            // below makes the silenced-vs-not-silenced delta visible on
            // the throughput sparkline and headline failed-tile.
            'App\\Jobs\\PingThirdPartyVendor',
        ];

        foreach ($classes as $class) {
            $redis->command('zadd', [KeyPrefix::make('classes'), $now->getTimestamp(), $class]);
            $redis->command('setex', [KeyPrefix::make("last_run:{$class}"), 2592000, $now->toIso8601String()]);

            // Per-hour throughput across the full 24h window the
            // dashboard's bar chart renders. Varies by hour-of-day with a
            // sine-shaped business-hours peak so the chart shows a real
            // diurnal pattern instead of a single tall bar at "now".
            $base = 30 + ((int) abs(crc32($class)) % 80);
            $failureWeight = match ($class) {
                'App\\Jobs\\PingThirdPartyVendor' => 0.45,
                'App\\Jobs\\NotifyImportFinished' => 0.18,
                'App\\Jobs\\GenerateInvoicePdf' => 0.14,
                default => 0.02,
            };
            for ($i = 23; $i >= 0; --$i) {
                $hour = $now->copy()->utc()->subHours($i)->startOfHour();
                $bucket = $hour->format('YmdH');

                // Diurnal scaler: 0.3 at night → 1.0 at midday, plus
                // class-specific phase offset so different classes peak
                // at slightly different hours.
                $phase = ((int) abs(crc32($class)) % 6) / 6.0; // 0..1
                $hourOfDay = ((int) $hour->format('G') + ($phase * 24)) % 24;
                $diurnal = 0.3 + 0.7 * (sin(($hourOfDay - 6) / 24 * 2 * M_PI) + 1) / 2;
                $processed = (int) round($base * $diurnal) + ($i % 5);
                $failed = (int) round($processed * $failureWeight) + ($i % 7 === 0 ? 1 : 0);

                $redis->command('setex', [
                    KeyPrefix::make("processed:{$class}:{$bucket}"),
                    7 * 86400,
                    (string) max(0, $processed),
                ]);
                if ($failed > 0) {
                    $redis->command('setex', [
                        KeyPrefix::make("failed:{$class}:{$bucket}"),
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

        // Filler rows so the completed list has multi-page content. No
        // chain / lineage on these so they don't pollute the demo flow.
        for ($i = 1; $i <= 30; $i++) {
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
        // Idempotent: skip when rows already exist. With shared MySQL on the
        // hosted demo, multiple workers handling concurrent `/` requests
        // would otherwise duplicate the seed rows on every refresh.
        if (DB::table('failed_jobs')->count() > 0) {
            return;
        }

        $rows = [
            [
                'uuid' => 'preview-uuid-failed-report',
                'connection' => 'sqs',
                'queue' => 'reports',
                'payload' => json_encode([
                    'uuid' => 'preview-uuid-failed-report',
                    'displayName' => 'App\\Jobs\\GenerateReport',
                    'attempts' => 3,
                    'maxTries' => 3,
                    'data' => ['batchId' => 'preview-batch-001'],
                ]),
                'exception' => "RuntimeException: Database connection timeout\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(8)->format('Y-m-d H:i:s'),
            ],
            [
                'uuid' => 'preview-uuid-failed-welcome',
                'connection' => 'redis',
                'queue' => 'mail',
                'payload' => json_encode([
                    'uuid' => 'preview-uuid-failed-welcome',
                    'displayName' => 'App\\Jobs\\SendWelcomeEmail',
                    'attempts' => 2,
                    'maxTries' => 3,
                ]),
                'exception' => "Swift_TransportException: SMTP server refused connection\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(20)->format('Y-m-d H:i:s'),
            ],
            [
                // Chained child whose backward lineage points at ProcessImport.
                'uuid' => 'preview-uuid-failed-child',
                'connection' => 'redis',
                'queue' => 'mail',
                'payload' => json_encode([
                    'uuid' => 'preview-uuid-failed-child',
                    'displayName' => 'App\\Jobs\\NotifyImportFinished',
                    'attempts' => 1,
                    'maxTries' => 1,
                ]),
                'exception' => "InvalidArgumentException: Malformed CSV row 482\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subHour()->format('Y-m-d H:i:s'),
            ],
            // Silenced-class rows — listed in `queue-insights.silenced` so
            // the Failed list hides them by default and the "Show silenced"
            // checkbox on the failed-pane filter form (URL `?fs=1`)
            // reveals them.
            [
                'uuid' => 'preview-uuid-silenced-vendor-1',
                'connection' => 'redis',
                'queue' => 'webhooks',
                'payload' => json_encode([
                    'uuid' => 'preview-uuid-silenced-vendor-1',
                    'displayName' => 'App\\Jobs\\PingThirdPartyVendor',
                    'attempts' => 3,
                    'maxTries' => 3,
                ]),
                'exception' => "GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 5000 ms\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(4)->format('Y-m-d H:i:s'),
            ],
            [
                'uuid' => 'preview-uuid-silenced-vendor-2',
                'connection' => 'redis',
                'queue' => 'webhooks',
                'payload' => json_encode([
                    'uuid' => 'preview-uuid-silenced-vendor-2',
                    'displayName' => 'App\\Jobs\\PingThirdPartyVendor',
                    'attempts' => 2,
                    'maxTries' => 3,
                ]),
                'exception' => "GuzzleHttp\\Exception\\ServerException: 503 Service Unavailable\n#0 /preview/Stack.php(1): preview()\n#1 {main}",
                'failed_at' => $now->copy()->subMinutes(11)->format('Y-m-d H:i:s'),
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
        $rows = [
            ['preview-pending-1', 'App\\Jobs\\SendWelcomeEmail', 'redis', 'default', $now->copy()->subSeconds(45), null],
            ['preview-pending-2', 'App\\Jobs\\GenerateReport', 'sqs', 'reports', $now->copy()->subMinutes(8), 'preview-batch-001'],
        ];

        foreach ($rows as [$uuid, $class, $connection, $queue, $queuedAt, $batchId]) {
            $this->writePendingHash(
                $redis,
                $uuid,
                $class,
                $connection,
                $queue,
                queuedAt: $queuedAt,
                availableAt: $queuedAt,
                batchId: $batchId,
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
        $rows = [
            [
                'preview-inflight-1', 'App\\Jobs\\ProcessImport', 'redis', 'default',
                $now->copy()->subSeconds(45), $now->copy()->subSeconds(20), null, null,
            ],
            [
                // Chained child running right now — `parent_uuid` set so
                // the in-flight modal demonstrates the backward link.
                'preview-inflight-2', 'App\\Jobs\\NotifyImportFinished', 'redis', 'mail',
                $now->copy()->subMinutes(3), $now->copy()->subMinutes(2), null,
                'preview-uuid-process-import',
            ],
        ];

        foreach ($rows as [$uuid, $class, $connection, $queue, $queuedAt, $startedAt, $batchId, $parentUuid]) {
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
        // Idempotent: skip when batches already exist. The Redis-side index
        // is rebuilt every seed (resetState flushes the prefix), but the
        // DB-side row would otherwise collide with the primary-key on
        // re-insertion across concurrent workers on shared MySQL.
        if (DB::table('job_batches')->count() > 0) {
            return;
        }

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
        DB::table('job_batches')->insert([
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
        ]);
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
        ];

        foreach ($queues as [$connection, $queue, $samples]) {
            $queueKey = CanonicalQueueKey::from($queue);
            $waitKey = KeyPrefix::make("wait:{$connection}:{$queueKey}");
            $i = 0;
            foreach ($samples as $waitMs) {
                $uuid = "preview-wait-{$connection}-{$queueKey}-{$i}";
                $redis->command('setex', [KeyPrefix::make("wait:{$uuid}"), 604800, (string) $waitMs]);
                $redis->command('zadd', [$waitKey, $now->copy()->subSeconds($i)->getTimestamp(), $uuid]);
                $i++;
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
        if ($startedAt !== null) {
            $fields['started_at'] = (string) $startedAt->getTimestamp();
        }
        if ($parentUuid !== null) {
            $fields['parent_uuid'] = $parentUuid;
        }

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

    private function redis(): RedisConnection
    {
        return Redis::connection(config()->get('queue-insights.redis_connection', 'default'));
    }
}
