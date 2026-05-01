<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\SerializedCommandReader;

it('returns null for non-object serialized values', function (): void {
    expect(SerializedCommandReader::extract(serialize('plain string')))->toBeNull()
        ->and(SerializedCommandReader::extract(serialize(['array', 'value'])))->toBeNull()
        ->and(SerializedCommandReader::extract(serialize(42)))->toBeNull()
        ->and(SerializedCommandReader::extract('not even valid serialized data'))->toBeNull();
});

it('extracts public properties from a serialized stdClass blob', function (): void {
    $obj = (object) ['videoId' => 18, 'userId' => 1, 'silent' => false];
    $result = SerializedCommandReader::extract(serialize($obj));

    expect($result)->toBeArray();
    assert(is_array($result));

    expect($result['class'])->toBe('stdClass')
        ->and($result['properties'])->toBe([
            'videoId' => 18,
            'userId' => 1,
            'silent' => false,
        ]);
});

it('cleans null-byte prefixes from protected and private property keys', function (): void {
    // Serialized payload simulating a class with public/protected/private props.
    // Format: O:8:"TestJob":3:{s:7:"timeout";i:780;s:9:"\x00*\x00videoId";i:18;s:14:"\x00TestJob\x00secret";s:4:"hush";}
    $blob = "O:7:\"TestJob\":3:{s:7:\"timeout\";i:780;s:10:\"\x00*\x00videoId\";i:18;s:15:\"\x00TestJob\x00secret\";s:4:\"hush\";}";

    $result = SerializedCommandReader::extract($blob);
    expect($result)->toBeArray();
    assert(is_array($result));

    expect($result['class'])->toBe('TestJob')
        ->and($result['properties'])->toBe([
            'timeout' => 780,
            'videoId' => 18,
            'secret' => 'hush',
        ]);
});

it('does not run constructors or __wakeup on the serialized class', function (): void {
    // If `allowed_classes => false` were missing, unserialize would try to instantiate
    // the class — but we don't have one named "ThisClassDoesNotExist", so the test
    // proves we don't error on unknown classes.
    $blob = 'O:21:"ThisClassDoesNotExist":1:{s:3:"foo";s:3:"bar";}';

    $result = SerializedCommandReader::extract($blob);
    expect($result)->toBeArray();
    assert(is_array($result));

    expect($result['class'])->toBe('ThisClassDoesNotExist')
        ->and($result['properties'])->toBe(['foo' => 'bar']);
});

it('summarizes scalars and nulls verbatim', function (): void {
    expect(SerializedCommandReader::summarize(null))->toBe('null')
        ->and(SerializedCommandReader::summarize(true))->toBe('true')
        ->and(SerializedCommandReader::summarize(false))->toBe('false')
        ->and(SerializedCommandReader::summarize(42))->toBe('42')
        ->and(SerializedCommandReader::summarize('hello'))->toBe('hello');
});

it('summarizes nested __PHP_Incomplete_Class objects with class name', function (): void {
    // Build a blob via serialize() of a stdClass alias, then unserialize with
    // allowed_classes=false to land in __PHP_Incomplete_Class — the same path
    // a nested object inside a real job's serialized payload would take.
    $blob = serialize((object) ['id' => 1]);
    $nested = unserialize($blob, ['allowed_classes' => false]);

    expect($nested)->toBeInstanceOf(__PHP_Incomplete_Class::class);

    $rendered = SerializedCommandReader::summarize($nested);

    expect($rendered)->toBe('stdClass {…}');
});

it('summarizes arrays as compact JSON', function (): void {
    expect(SerializedCommandReader::summarize([1, 2, 3]))->toBe('[1,2,3]')
        ->and(SerializedCommandReader::summarize(['k' => 'v']))->toBe('{"k":"v"}');
});

it('expands a nested __PHP_Incomplete_Class instance to its property map', function (): void {
    // Outer object with a nested object — both go through allowed_classes=false
    // and become __PHP_Incomplete_Class instances. Build via serialize() so we
    // don't have to hand-craft the byte counts.
    $inner = (object) ['items' => ['foo'], 'count' => 1];
    $outer = (object) ['nested' => $inner];

    $extracted = SerializedCommandReader::extract(serialize($outer));
    expect($extracted)->toBeArray();
    assert(is_array($extracted));

    $nested = $extracted['properties']['nested'] ?? null;
    expect($nested)->toBeInstanceOf(__PHP_Incomplete_Class::class);
    assert($nested instanceof __PHP_Incomplete_Class);

    expect(SerializedCommandReader::classNameOf($nested))->toBe('stdClass');

    $expanded = SerializedCommandReader::expandObject($nested);
    expect($expanded)->toBe([
        'items' => ['foo'],
        'count' => 1,
    ]);
});

it('classNameOf returns null for objects missing the incomplete-class name marker', function (): void {
    $obj = new stdClass();

    expect(SerializedCommandReader::classNameOf($obj))->toBeNull();
});

it('extractChainContext returns the next class + remaining + connection + queue when chained is non-empty', function (): void {
    // Hand-built shape mirroring Laravel's `Queueable` trait — protected
    // `chained` array of pre-serialized job bodies, plus `chainConnection` /
    // `chainQueue`. byte counts computed from strlen so the test is robust
    // across class-name renames.
    $nextClass = 'App\\Jobs\\NextJob';
    $afterClass = 'App\\Jobs\\AnotherJob';
    $outerClass = 'App\\Jobs\\ChainedJob';

    $nextJob = 'O:' . strlen($nextClass) . ':"' . $nextClass . '":0:{}';
    $afterJob = 'O:' . strlen($afterClass) . ':"' . $afterClass . '":0:{}';

    $blob = 'O:' . strlen($outerClass) . ':"' . $outerClass . '":3:{'
        . "s:10:\"\x00*\x00chained\";a:2:{i:0;s:" . strlen($nextJob) . ':"' . $nextJob . '";i:1;s:' . strlen($afterJob) . ':"' . $afterJob . '";}'
        . "s:18:\"\x00*\x00chainConnection\";s:5:\"redis\";"
        . "s:13:\"\x00*\x00chainQueue\";s:7:\"default\";"
        . '}';

    $result = SerializedCommandReader::extractChainContext($blob);

    expect($result)->toBe([
        'next_class' => 'App\\Jobs\\NextJob',
        'remaining' => 2,
        'chain_connection' => 'redis',
        'chain_queue' => 'default',
        'jobs' => [
            ['class' => 'App\\Jobs\\NextJob', 'connection' => 'redis', 'queue' => 'default', 'properties' => []],
            ['class' => 'App\\Jobs\\AnotherJob', 'connection' => 'redis', 'queue' => 'default', 'properties' => []],
        ],
    ]);
});

it('extractChainContext returns null when chained is absent', function (): void {
    $blob = serialize((object) ['videoId' => 18]);

    expect(SerializedCommandReader::extractChainContext($blob))->toBeNull();
});

it('extractChainContext returns null when chained is empty (last link)', function (): void {
    $outerClass = 'App\\Jobs\\ChainedJob';
    $blob = 'O:' . strlen($outerClass) . ':"' . $outerClass . "\":1:{s:10:\"\x00*\x00chained\";a:0:{}}";

    expect(SerializedCommandReader::extractChainContext($blob))->toBeNull();
});

it('extractChainContext returns null on malformed payload', function (): void {
    expect(SerializedCommandReader::extractChainContext('not serialized'))->toBeNull()
        ->and(SerializedCommandReader::extractChainContext(''))->toBeNull();
});

it('extractChainContext returns null on encrypted (base64-blob) command', function (): void {
    // Encrypted jobs ship `data.command` as a base64-encoded ciphertext blob,
    // which `unserialize` rejects. The reader must fail closed without erroring.
    $encrypted = base64_encode(random_bytes(64));

    expect(SerializedCommandReader::extractChainContext($encrypted))->toBeNull();
});

it('extractChainContext handles the public-property layout Laravel Queueable actually serializes', function (): void {
    // Laravel's `Illuminate\Bus\Queueable` declares `chained`, `chainConnection`,
    // and `chainQueue` as PUBLIC properties (no `\0*\0` prefix in the serialized
    // form). The other extractChainContext tests above use the protected
    // encoding to be defensive, but production payloads land in this shape.
    $nextClass = 'App\\Jobs\\NextJob';
    $nextJob = 'O:' . strlen($nextClass) . ':"' . $nextClass . '":0:{}';

    $obj = new stdClass();
    $obj->chained = [$nextJob];
    $obj->chainConnection = 'redis';
    $obj->chainQueue = 'default';

    $result = SerializedCommandReader::extractChainContext(serialize($obj));

    expect($result)->toBe([
        'next_class' => 'App\\Jobs\\NextJob',
        'remaining' => 1,
        'chain_connection' => 'redis',
        'chain_queue' => 'default',
        'jobs' => [
            ['class' => 'App\\Jobs\\NextJob', 'connection' => 'redis', 'queue' => 'default', 'properties' => []],
        ],
    ]);
});

it('extractChainContext prefers the next jobs own connection/queue over the outer chain defaults', function (): void {
    // Mirrors Queueable::dispatchNextJobInChain — `$next->connection` /
    // `$next->queue` (when set on the next job itself) win over the parent's
    // `chainConnection` / `chainQueue` fallbacks.
    $next = new stdClass();
    $next->connection = 'sqs';
    $next->queue = 'priority';

    $outer = new stdClass();
    $outer->chained = [serialize($next)];
    $outer->chainConnection = 'redis';
    $outer->chainQueue = 'default';

    $result = SerializedCommandReader::extractChainContext(serialize($outer));

    expect($result)->toBe([
        'next_class' => 'stdClass',
        'remaining' => 1,
        'chain_connection' => 'sqs',
        'chain_queue' => 'priority',
        'jobs' => [
            ['class' => 'stdClass', 'connection' => 'sqs', 'queue' => 'priority', 'properties' => []],
        ],
    ]);
});

it('extractChainContext falls back to outer chainConnection/chainQueue when the next job has none', function (): void {
    $next = new stdClass(); // no connection / queue overrides
    $outer = new stdClass();
    $outer->chained = [serialize($next)];
    $outer->chainConnection = 'redis';
    $outer->chainQueue = 'default';

    $result = SerializedCommandReader::extractChainContext(serialize($outer));

    expect($result)->toBe([
        'next_class' => 'stdClass',
        'remaining' => 1,
        'chain_connection' => 'redis',
        'chain_queue' => 'default',
        'jobs' => [
            ['class' => 'stdClass', 'connection' => 'redis', 'queue' => 'default', 'properties' => []],
        ],
    ]);
});

it('extractChainContext fails closed when ANY chained entry is malformed (not just the first)', function (): void {
    // Codex review: silently skipping a malformed mid-list entry would
    // misorder the chain — the next valid entry would claim the "next" slot
    // it doesn't actually own — and would let the completed-stream count
    // diverge from the failed-job-payload count. One bad entry = no chain
    // context at all. Strict failure mode keeps both surfaces consistent.
    $valid = serialize(new stdClass());
    $malformed = 'O:99:"App\\Jobs\\Spoof":5:{';

    $outer = new stdClass();
    $outer->chained = [$valid, $malformed, $valid];

    expect(SerializedCommandReader::extractChainContext(serialize($outer)))->toBeNull();
});

it('extractChainContext fails closed when chained[0] looks like O:... but does not parse', function (): void {
    // The header `O:99:"App\\Jobs\\Spoof":...` declares a 99-char class name
    // and 5 properties, but the body is empty. Header-regex parsing alone
    // would happily return 'App\\Jobs\\Spoof'; safely re-extracting the
    // payload rejects it because it isn't a valid serialized object.
    $spoofed = 'O:99:"App\\Jobs\\Spoof":5:{';

    $outer = new stdClass();
    $outer->chained = [$spoofed];

    $result = SerializedCommandReader::extractChainContext(serialize($outer));

    expect($result)->toBeNull();
});

it('extractChainContext omits chain_connection/chain_queue when not set on the job', function (): void {
    $nextClass = 'App\\Jobs\\NextJob';
    $outerClass = 'App\\Jobs\\ChainedJob';
    $nextJob = 'O:' . strlen($nextClass) . ':"' . $nextClass . '":0:{}';

    $blob = 'O:' . strlen($outerClass) . ':"' . $outerClass . '":1:{'
        . "s:10:\"\x00*\x00chained\";a:1:{i:0;s:" . strlen($nextJob) . ':"' . $nextJob . '";}'
        . '}';

    $result = SerializedCommandReader::extractChainContext($blob);

    expect($result)->toBe([
        'next_class' => 'App\\Jobs\\NextJob',
        'remaining' => 1,
        'chain_connection' => null,
        'chain_queue' => null,
        'jobs' => [
            ['class' => 'App\\Jobs\\NextJob', 'connection' => null, 'queue' => null, 'properties' => []],
        ],
    ]);
});

it('extractChainContext exposes chained-job constructor properties (filtering framework internals)', function (): void {
    // Hand-build a serialized job carrying both user data (videoId, payload)
    // and Laravel framework internals (queue, connection, maxTries, batchId).
    // The framework keys must be filtered out — they're already shown as
    // chips in the modal — leaving only the user-bound properties for the
    // chain-detail view.
    $next = new stdClass();
    $next->videoId = 42;
    $next->payload = ['notify' => true, 'retries' => 3];
    $next->queue = 'priority';        // framework — filtered
    $next->connection = 'sqs';        // framework — filtered
    $next->maxTries = 5;              // framework — filtered
    $next->batchId = 'batch-xyz';     // framework — filtered

    $outer = new stdClass();
    $outer->chained = [serialize($next)];

    $result = SerializedCommandReader::extractChainContext(serialize($outer));

    expect($result)->not->toBeNull();
    if ($result === null) {
        return;
    }

    expect($result['jobs'][0]['class'])->toBe('stdClass')
        ->and($result['jobs'][0]['properties'])->toBe([
            'videoId' => 42,
            'payload' => ['notify' => true, 'retries' => 3],
        ])
        // Connection/queue still surface from the framework keys for the chips
        // — only filtered out of `properties`.
        ->and($result['jobs'][0]['connection'])->toBe('sqs')
        ->and($result['jobs'][0]['queue'])->toBe('priority');
});
