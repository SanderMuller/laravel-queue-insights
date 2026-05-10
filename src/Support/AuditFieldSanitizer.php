<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

/**
 * Single source of the audit-log neutralisation rule for URL-bound filter
 * fields: replace anything outside printable ASCII with `?` (log-injection
 * defence) and cap length at 80 chars (audit-log size guard).
 *
 * @internal
 */
final class AuditFieldSanitizer
{
    public static function clean(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $clean = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return mb_substr($clean, 0, 80);
    }
}
