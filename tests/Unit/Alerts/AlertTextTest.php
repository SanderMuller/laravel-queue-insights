<?php declare(strict_types=1);

use SanderMuller\QueueInsights\Alerts\AlertText;

it('returns an empty string for null or empty input', function (): void {
    expect(AlertText::sanitise(null))->toBe('')
        ->and(AlertText::sanitise(''))->toBe('');
});

it('collapses whitespace-only strings to an empty string', function (): void {
    expect(AlertText::sanitise("   \t  "))->toBe('')
        ->and(AlertText::sanitise("\n\n\r"))->toBe('');
});

it('strips Unicode control characters and collapses internal whitespace', function (): void {
    expect(AlertText::sanitise("Export\nnightly\rreports\t\u{0007}done"))
        ->toBe('Export nightly reports done');
});

it('trims surrounding whitespace after sanitising', function (): void {
    expect(AlertText::sanitise('  hello  '))->toBe('hello');
});

it('caps output at 200 chars with an ellipsis', function (): void {
    $long = str_repeat('a', 250);
    $result = AlertText::sanitise($long);

    expect(mb_strlen($result))->toBe(200)
        ->and(str_ends_with($result, '...'))->toBeTrue();
});

it('preserves printable Unicode (non-control codepoints)', function (): void {
    expect(AlertText::sanitise('Rapport — naïve résumé ✓'))
        ->toBe('Rapport — naïve résumé ✓');
});
