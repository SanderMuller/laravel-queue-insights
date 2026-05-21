<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\InitiatorStore;
use SanderMuller\QueueInsights\Tests\Support\R;
use SanderMuller\QueueInsights\Tests\Support\RedisAvailability;

beforeEach(function (): void {
    if (! RedisAvailability::check()) {
        $this->markTestSkipped('redis not available on this host');
    }

    RedisAvailability::flush();

    config()->set('queue-insights.enabled', true);
    config()->set('queue-insights.key_prefix', 'qmtest:');
});

it('writes the given fields onto qi:initiator:{uuid} and sets the TTL', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69INIT01';

    (new InitiatorStore())->write($uuid, ['origin' => 'http:checkout.store'], 604800);

    expect(R::str('hget', 'qmtest:initiator:' . $uuid, 'origin'))->toBe('http:checkout.store');

    $ttl = R::int('ttl', 'qmtest:initiator:' . $uuid);
    expect($ttl)->toBeGreaterThan(604790)->toBeLessThanOrEqual(604800);
});

it('skips empty-string fields rather than persisting blanks', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69INIT02';

    (new InitiatorStore())->write($uuid, ['origin' => 'artisan:app:sync', 'call_site' => ''], 3600);

    expect(R::str('hget', 'qmtest:initiator:' . $uuid, 'origin'))->toBe('artisan:app:sync')
        ->and(R::int('hexists', 'qmtest:initiator:' . $uuid, 'call_site'))->toBe(0);
});

it('no-ops the whole write when no field survives the empty filter', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69INIT03';

    (new InitiatorStore())->write($uuid, ['origin' => '', 'call_site' => ''], 3600);

    expect(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(0);
});

it('no-ops when the uuid is empty or the TTL is non-positive', function (): void {
    (new InitiatorStore())->write('', ['origin' => 'http:foo'], 3600);
    (new InitiatorStore())->write('01ARZ3NDEKTSV4RRFFQ69INIT04', ['origin' => 'http:foo'], 0);

    expect(R::int('exists', 'qmtest:initiator:'))->toBe(0)
        ->and(R::int('exists', 'qmtest:initiator:01ARZ3NDEKTSV4RRFFQ69INIT04'))->toBe(0);
});

it('reads back origin / call_site as a typed pair', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69INIT05';

    (new InitiatorStore())->write($uuid, [
        'origin' => 'schedule:backup-db',
        'call_site' => 'app/Console/Kernel.php:42',
    ], 3600);

    expect((new InitiatorStore())->read($uuid))->toBe([
        'origin' => 'schedule:backup-db',
        'call_site' => 'app/Console/Kernel.php:42',
    ]);
});

it('returns nulls from read when the key is absent', function (): void {
    expect((new InitiatorStore())->read('01ARZ3NDEKTSV4RRFFQ69MISSING'))->toBe([
        'origin' => null,
        'call_site' => null,
    ])
        ->and((new InitiatorStore())->read(''))->toBe(['origin' => null, 'call_site' => null]);
});

it('shortenTtl shrinks the key TTL without deleting it', function (): void {
    $uuid = '01ARZ3NDEKTSV4RRFFQ69INIT06';

    (new InitiatorStore())->write($uuid, ['origin' => 'http:foo'], 604800);
    (new InitiatorStore())->shortenTtl($uuid, 60);

    $ttl = R::int('ttl', 'qmtest:initiator:' . $uuid);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        // The key still exists — shortenTtl never DELs.
        ->and(R::int('exists', 'qmtest:initiator:' . $uuid))->toBe(1);
});
