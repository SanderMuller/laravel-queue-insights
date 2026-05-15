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
