<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Prometheus\Exposition\EscapeLabel;

it('escapes backslash, double-quote, and newline', function (): void {
    expect(EscapeLabel::value('a\\b'))->toBe('a\\\\b')
        ->and(EscapeLabel::value('a"b'))
        ->toBe('a\"b')
        ->and(EscapeLabel::value("a\nb"))
        ->toBe('a\nb');
});

it('leaves other characters intact', function (): void {
    expect(EscapeLabel::value('App\\Jobs\\Foo'))->toBe('App\\\\Jobs\\\\Foo')
        ->and(EscapeLabel::value('plain text'))
        ->toBe('plain text')
        ->and(EscapeLabel::value('utf8 — ñ'))
        ->toBe('utf8 — ñ');
});

it('validates metric names against the prometheus shape', function (): void {
    expect(EscapeLabel::isValidMetricName('queue_insights_queue_depth'))->toBeTrue()
        ->and(EscapeLabel::isValidMetricName('a:b:c'))
        ->toBeTrue()
        ->and(EscapeLabel::isValidMetricName('_under'))
        ->toBeTrue()
        ->and(EscapeLabel::isValidMetricName('1invalid'))
        ->toBeFalse()
        ->and(EscapeLabel::isValidMetricName('has space'))
        ->toBeFalse()
        ->and(EscapeLabel::isValidMetricName(''))
        ->toBeFalse()
        ->and(EscapeLabel::isValidMetricName('a-b'))
        ->toBeFalse();
});
