<?php declare(strict_types=1);

use Livewire\Livewire;
use SanderMuller\QueueInsights\Http\Livewire\QueueInsightsDashboard;
use SanderMuller\QueueInsights\Support\KeyPrefix;
use SanderMuller\QueueInsights\Support\SilencedJobs;
use SanderMuller\QueueInsights\Support\UuidResolver;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    config()->set('queue-insights.silenced', []);
});

it('isSilenced returns false when the silenced list is empty', function (): void {
    expect((new SilencedJobs())->isSilenced('App\\Jobs\\Foo'))->toBeFalse();
});

it('isSilenced returns true on an exact match', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy', 'App\\Jobs\\Other']);

    expect((new SilencedJobs())->isSilenced('App\\Jobs\\Noisy'))->toBeTrue();
});

it('isSilenced returns false on a non-match', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    expect((new SilencedJobs())->isSilenced('App\\Jobs\\Quiet'))->toBeFalse();
});

it('isSilenced returns false for an empty class string even when an empty entry sneaks into config', function (): void {
    // Defensive: ResolveJobClass can return '' in fallback paths. Treat
    // that as "not silenced" rather than risk a falsy lookup matching.
    // The validator forbids empty entries, but this is the runtime safety
    // belt regardless.
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    expect((new SilencedJobs())->isSilenced(''))->toBeFalse();
});

it('all() returns the silenced list in order (with non-string entries dropped)', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\A', 'App\\Jobs\\B']);

    expect((new SilencedJobs())->all())->toBe(['App\\Jobs\\A', 'App\\Jobs\\B']);
});

it('matches synthetic class labels (Closure@hash)', function (): void {
    config()->set('queue-insights.silenced', ['Closure@abc123', 'Encrypted@deadbeef']);

    $silenced = new SilencedJobs();
    expect($silenced->isSilenced('Closure@abc123'))->toBeTrue()
        ->and($silenced->isSilenced('Encrypted@deadbeef'))->toBeTrue()
        ->and($silenced->isSilenced('Closure@other'))->toBeFalse();
});

it('app()->scoped binding produces a fresh instance after a scope flush (Octane parity)', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\First']);
    $first = resolve(SilencedJobs::class);
    expect($first->isSilenced('App\\Jobs\\First'))->toBeTrue();

    // Mutate config + flush the scoped instance — the next resolve should
    // pick up the new config snapshot. This mirrors Octane's per-request
    // reset of scoped bindings.
    config()->set('queue-insights.silenced', ['App\\Jobs\\Second']);
    app()->forgetScopedInstances();

    $second = resolve(SilencedJobs::class);
    expect($second->isSilenced('App\\Jobs\\First'))->toBeFalse()
        ->and($second->isSilenced('App\\Jobs\\Second'))->toBeTrue();
});

it('UuidResolver::resolve still hits silenced uuids on every surface (modal-by-uuid bypasses silencing)', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    // Silenced classes must still resolve when addressed by uuid. The
    // dashboard's list / aggregate surfaces are filtered, but a chain-
    // lineage parent uuid or batch-detail item link must always open
    // the modal — silencing is a list-level filter, not an access gate.
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);

    R::conn()->command('setex', [KeyPrefix::make('uuid-completed:noisy-completed'), 300, '1700000000-0']);
    R::conn()->command('setex', [KeyPrefix::make('uuid-failed:noisy-failed'), 300, '42']);
    R::conn()->command('hset', [KeyPrefix::make('pending:noisy-pending'), 'class', 'App\\Jobs\\Noisy']);

    expect(UuidResolver::resolve('noisy-completed'))
        ->toBe(['type' => 'completed', 'id' => '1700000000-0'])
        ->and(UuidResolver::resolve('noisy-failed'))
        ->toBe(['type' => 'failed', 'id' => 42])
        ->and(UuidResolver::resolve('noisy-pending'))
        ->toBe(['type' => 'pending', 'id' => 'noisy-pending']);
});

it('openByUuid still opens the failed modal for a silenced class uuid', function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();
    config()->set('queue-insights.key_prefix', 'qmtest:');
    config()->set('queue-insights.silenced', ['App\\Jobs\\Muted']);

    R::conn()->command('setex', [KeyPrefix::make('uuid-failed:silenced-uuid'), 300, '99']);

    Livewire::test(QueueInsightsDashboard::class)
        ->call('openByUuid', 'silenced-uuid')
        ->assertSet('selectedFailedId', 99);
});
