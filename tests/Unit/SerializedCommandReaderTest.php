<?php

declare(strict_types=1);

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
