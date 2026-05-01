<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Support\Config;

describe('Config::string', function (): void {
    it('returns the string value from config', function (): void {
        config()->set('queue-insights.foo', 'bar');

        expect(Config::string('foo'))->toBe('bar');
    });

    it('returns the default when the key is missing', function (): void {
        expect(Config::string('missing', 'fallback'))->toBe('fallback');
    });

    it('returns the default when the value is not a string', function (): void {
        config()->set('queue-insights.int_value', 42);

        expect(Config::string('int_value', 'fallback'))->toBe('fallback');
    });
});

describe('Config::int', function (): void {
    it('returns int values', function (): void {
        config()->set('queue-insights.n', 42);

        expect(Config::int('n'))->toBe(42);
    });

    it('coerces numeric strings', function (): void {
        config()->set('queue-insights.n', '7');

        expect(Config::int('n'))->toBe(7);
    });

    it('coerces floats', function (): void {
        config()->set('queue-insights.n', 3.9);

        expect(Config::int('n'))->toBe(3);
    });

    it('returns default for non-numeric values', function (): void {
        config()->set('queue-insights.n', 'abc');

        expect(Config::int('n', 99))->toBe(99);
    });
});

describe('Config::bool', function (): void {
    it('returns real bool values verbatim', function (): void {
        config()->set('queue-insights.flag', true);
        expect(Config::bool('flag'))->toBeTrue();

        config()->set('queue-insights.flag', false);
        expect(Config::bool('flag', true))->toBeFalse();
    });

    it('treats "1" / "0" string values as true / false', function (): void {
        config()->set('queue-insights.flag', '0');
        expect(Config::bool('flag', true))->toBeFalse();

        config()->set('queue-insights.flag', '1');
        expect(Config::bool('flag'))->toBeTrue();
    });

    it('treats common boolean-ish strings', function (): void {
        config()->set('queue-insights.flag', 'true');
        expect(Config::bool('flag'))->toBeTrue();

        config()->set('queue-insights.flag', 'false');
        expect(Config::bool('flag', true))->toBeFalse();

        config()->set('queue-insights.flag', 'yes');
        expect(Config::bool('flag'))->toBeTrue();

        config()->set('queue-insights.flag', 'off');
        expect(Config::bool('flag', true))->toBeFalse();
    });

    it('falls back to default for ambiguous strings', function (): void {
        config()->set('queue-insights.flag', 'maybe');

        expect(Config::bool('flag', true))->toBeTrue();
    });
});

describe('Config::array', function (): void {
    it('returns arrays verbatim', function (): void {
        config()->set('queue-insights.list', [1, 2, 3]);

        expect(Config::array('list'))->toBe([1, 2, 3]);
    });

    it('returns empty array for non-arrays', function (): void {
        config()->set('queue-insights.list', 'not-an-array');

        expect(Config::array('list'))->toBeEmpty();
    });

    it('returns empty array when missing', function (): void {
        expect(Config::array('nonexistent_key'))->toBeEmpty();
    });
});
