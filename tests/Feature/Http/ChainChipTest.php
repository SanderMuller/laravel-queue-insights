<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.snapshots', []);

    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->nullable();
        $table->string('connection');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('failed_jobs');
});

it('completed-row renders the chain chip when chain field is present', function (): void {
    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Jobs\\Step1',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
        'chain' => (string) json_encode([
            ['class' => 'App\\Jobs\\Step2', 'connection' => 'redis', 'queue' => 'default'],
            ['class' => 'App\\Jobs\\Step3', 'connection' => 'redis', 'queue' => 'default'],
            ['class' => 'App\\Jobs\\Step4', 'connection' => 'redis', 'queue' => 'default'],
        ]),
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        // Last segment of the FQCN is the chip text.
        ->assertSee('Step2')
        // (+remaining-1) suffix — 3 chained → +2 more after the one shown.
        ->assertSee('(+2)')
        // Title attribute carries full FQCN + remaining count.
        ->assertSeeHtml('Next: App\\Jobs\\Step2 (3 chained)');
});

it('completed-row omits the chain chip when chain field is absent', function (): void {
    seedStream(Redis::connection('default'), KeyPrefix::make('completed'), [
        'class' => 'App\\Jobs\\Solo',
        'connection' => 'redis',
        'queue' => 'default',
        'duration_ms' => '100',
        'attempts' => '1',
        'processed_at' => '2026-04-24T12:00:00+00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSeeHtml('Next: ');
});

it('failed-list-row renders the chain chip when payload.data.command carries a chained array', function (): void {
    $nextClass = 'App\\Jobs\\Next';
    $outerClass = 'App\\Jobs\\Outer';
    $nextJob = 'O:' . strlen($nextClass) . ':"' . $nextClass . '":0:{}';
    $command = 'O:' . strlen($outerClass) . ':"' . $outerClass . '":1:{'
        . "s:10:\"\x00*\x00chained\";a:1:{i:0;s:" . strlen($nextJob) . ':"' . $nextJob . '";}'
        . '}';

    DB::table('failed_jobs')->insert([
        'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => $outerClass,
            'maxTries' => 1,
            'attempts' => 1,
            'data' => ['commandName' => $outerClass, 'command' => $command],
        ]),
        'exception' => 'RuntimeException: nope',
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertSee('Next')
        ->assertSeeHtml('Next: App\\Jobs\\Next (1 chained)');
});

it('failed-list-row omits the chain chip when no chain present', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Solo', 'attempts' => 1, 'maxTries' => 1]),
        'exception' => 'RuntimeException: nope',
        'failed_at' => '2026-04-24 12:00:00',
    ]);

    Livewire::test(QueueInsightsDashboard::class)
        ->assertDontSeeHtml('Next: ');
});
