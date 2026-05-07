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
    config()->set('queue-insights.silenced_patterns', []);
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

it('matches a Str::is glob pattern when exact-match misses', function (): void {
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    $silenced = new SilencedJobs();
    expect($silenced->isSilenced('App\\Jobs\\Reports\\Daily'))->toBeTrue()
        ->and($silenced->isSilenced('App\\Jobs\\Reports\\Weekly'))->toBeTrue()
        ->and($silenced->isSilenced('App\\Jobs\\Other'))->toBeFalse();
});

it('exact-match wins when both lists are populated', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Foo']);
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Bar\\*']);

    $silenced = new SilencedJobs();
    expect($silenced->isSilenced('App\\Jobs\\Foo'))->toBeTrue()
        ->and($silenced->isSilenced('App\\Jobs\\Bar\\Quux'))->toBeTrue()
        ->and($silenced->isSilenced('App\\Jobs\\Other'))->toBeFalse();
});

it('all() returns exact list only — patterns are not enumerable', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Foo']);
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    expect((new SilencedJobs())->all())->toBe(['App\\Jobs\\Foo']);
});

it('hasAny() reports true when only patterns are set', function (): void {
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    expect((new SilencedJobs())->hasAny())->toBeTrue();
});

it('hasAny() reports false when both lists are empty', function (): void {
    expect((new SilencedJobs())->hasAny())->toBeFalse();
});

it('empty pattern list short-circuits the fallback path', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Foo']);

    expect((new SilencedJobs())->isSilenced('App\\Jobs\\Anything'))->toBeFalse();
});

it('isSilenced returns false for an empty class even when a glob is "*"', function (): void {
    // Defensive: Str::is('*', '') returns true in some Laravel versions; our
    // empty-class guard runs before pattern matching so the empty-string
    // fallback path stays "not silenced" regardless of glob shape.
    config()->set('queue-insights.silenced_patterns', ['*']);

    expect((new SilencedJobs())->isSilenced(''))->toBeFalse();
});

it('matches case-insensitively across exact list and patterns (parity with SQL LOWER path)', function (): void {
    // The SQL exclusion path lowercases both sides so URL-filter casing
    // doesn't matter. isSilenced must do the same so a lowercase config
    // entry doesn't hide rows in SQL while leaving them visible on the
    // dashboard / detector / dispatcher / throughput / completed-row
    // paths. Codex review surfaced this drift.
    config()->set('queue-insights.silenced', ['app\\jobs\\noisy']);
    config()->set('queue-insights.silenced_patterns', ['app\\jobs\\reports\\*']);

    $silenced = new SilencedJobs();
    expect($silenced->isSilenced('App\\Jobs\\Noisy'))->toBeTrue()
        ->and($silenced->isSilenced('App\\Jobs\\Reports\\Daily'))->toBeTrue()
        ->and($silenced->isSilenced('APP\\JOBS\\REPORTS\\WEEKLY'))->toBeTrue()
        ->and($silenced->isSilenced('App\\Jobs\\Other'))->toBeFalse();
});

it('all() / patterns() preserve operator-supplied casing for display', function (): void {
    config()->set('queue-insights.silenced', ['App\\Jobs\\Noisy']);
    config()->set('queue-insights.silenced_patterns', ['App\\Jobs\\Reports\\*']);

    $silenced = new SilencedJobs();
    expect($silenced->all())->toBe(['App\\Jobs\\Noisy'])
        ->and($silenced->patterns())->toBe(['App\\Jobs\\Reports\\*']);
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
