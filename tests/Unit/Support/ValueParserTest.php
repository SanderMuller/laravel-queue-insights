<?php

declare(strict_types=1);

use SanderMuller\QueueInsights\Support\ValueParser;

it('returns null for strings too short to be a valid serialized container', function (): void {
    expect(ValueParser::parse(''))->toBeNull();
    expect(ValueParser::parse('hi'))->toBeNull();
    expect(ValueParser::parse('a:0:'))->toBeNull();
});

it('returns null for arbitrary plain strings', function (): void {
    expect(ValueParser::parse('hello world'))->toBeNull();
    expect(ValueParser::parse('https://example.com/path'))->toBeNull();
    expect(ValueParser::parse('2026-05-15T12:00:00+00:00'))->toBeNull();
});

it('parses a PHP-serialized associative array', function (): void {
    $serialized = serialize(['locale' => 'en', 'user_id' => 42]);

    expect(ValueParser::parse($serialized))->toBe(['locale' => 'en', 'user_id' => 42]);
});

it('parses a PHP-serialized list', function (): void {
    $serialized = serialize(['one', 'two', 'three']);

    expect(ValueParser::parse($serialized))->toBe(['one', 'two', 'three']);
});

it('returns null when unserialize would yield an empty array', function (): void {
    // Empty containers parse but offer the operator nothing to drill into,
    // so the renderer falls back to the plain-string path.
    expect(ValueParser::parse('a:0:{}'))->toBeNull();
});

it('parses a PHP-serialized object without instantiating its constructor', function (): void {
    $serialized = 'O:8:"stdClass":2:{s:6:"locale";s:2:"en";s:7:"user_id";i:42;}';

    $parsed = ValueParser::parse($serialized);

    expect($parsed)
        ->toBeArray()
        ->toHaveKey('__class', 'stdClass')
        ->toHaveKey('locale', 'en')
        ->toHaveKey('user_id', 42);
});

it('parses a JSON object container', function (): void {
    expect(ValueParser::parse('{"locale":"en","user_id":42}'))
        ->toBe(['locale' => 'en', 'user_id' => 42]);
});

it('parses a JSON array container', function (): void {
    expect(ValueParser::parse('["one","two","three"]'))->toBe(['one', 'two', 'three']);
});

it('returns null for a JSON scalar (quoted string)', function (): void {
    // `"hello"` is valid JSON but not a container — leave it alone so the
    // renderer keeps showing the raw string.
    expect(ValueParser::parse('"hello there"'))->toBeNull();
});

it('returns null for a malformed serialized blob', function (): void {
    expect(ValueParser::parse('a:5:{nope}'))->toBeNull();
});

it('does not instantiate restricted classes', function (): void {
    // ArrayObject would normally hydrate; allowed_classes=false forces a
    // __PHP_Incomplete_Class fallback. The contract: the constructor must
    // not run. We assert that the round-trip surfaces the original class
    // name under the `__class` key rather than executing user code.
    $obj = new ArrayObject(['k' => 'v']);
    $serialized = serialize($obj);

    $parsed = ValueParser::parse($serialized);

    expect($parsed)->not->toBeNull()
        ->and($parsed['__class'] ?? null)->toBe('ArrayObject');
});

it('decodes a PHP-serialized scalar string leaf', function (): void {
    expect(ValueParser::decodeScalar('s:5:"hello";'))->toBe(['value' => 'hello']);
});

it('decodes a PHP-serialized int leaf', function (): void {
    expect(ValueParser::decodeScalar('i:42;'))->toBe(['value' => 42]);
    expect(ValueParser::decodeScalar('i:-7;'))->toBe(['value' => -7]);
});

it('decodes a PHP-serialized bool leaf (true + false)', function (): void {
    expect(ValueParser::decodeScalar('b:1;'))->toBe(['value' => true]);
    expect(ValueParser::decodeScalar('b:0;'))->toBe(['value' => false]);
});

it('decodes a PHP-serialized float leaf', function (): void {
    // Use serialize() directly — PHP emits floats at maximum precision
    // (e.g. `d:3.140000000000000124…;`), so a hand-written `d:3.14;`
    // would never round-trip cleanly. Real Context::dehydrate() output
    // always comes from serialize(), so the canonical form is the
    // contract.
    expect(ValueParser::decodeScalar(serialize(3.14)))->toBe(['value' => 3.14]);
});

it('rejects scalars with trailing garbage (full-input round-trip)', function (): void {
    // PHP's unserialize() consumes the leading scalar token and ignores
    // trailing bytes — `i:42;garbage;` decodes to 42, `N;;` decodes to
    // null. Without the strict round-trip equality these would launder
    // corrupt payload data into "trusted" values on the modal. Caught
    // by codex review on the original implementation.
    expect(ValueParser::decodeScalar('i:42;garbage;'))->toBeNull();
    expect(ValueParser::decodeScalar('s:5:"hello";junk;'))->toBeNull();
    expect(ValueParser::decodeScalar('d:3.14;tail;'))->toBeNull();
    expect(ValueParser::decodeScalar('N;garbage;'))->toBeNull();
    expect(ValueParser::decodeScalar('N;;'))->toBeNull();
    expect(ValueParser::decodeScalar('b:1;extra;'))->toBeNull();
    expect(ValueParser::decodeScalar('b:0;extra;'))->toBeNull();
});

it('decodes a PHP-serialized null leaf', function (): void {
    expect(ValueParser::decodeScalar('N;'))->toBe(['value' => null]);
});

it('returns null for a container opener — that is parse()s job', function (): void {
    expect(ValueParser::decodeScalar('a:1:{s:1:"a";i:1;}'))->toBeNull();
    expect(ValueParser::decodeScalar('O:8:"stdClass":0:{}'))->toBeNull();
});

it('returns null for a malformed scalar', function (): void {
    expect(ValueParser::decodeScalar('s:notvalid'))->toBeNull();
    expect(ValueParser::decodeScalar('i:abc;'))->toBeNull();
});

it('returns null for plain strings that just happen to start with a scalar opener', function (): void {
    expect(ValueParser::decodeScalar('snake_case_key'))->toBeNull();
    expect(ValueParser::decodeScalar('bonus payment'))->toBeNull();
});
