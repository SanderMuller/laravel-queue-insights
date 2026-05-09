<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Scheduler;

/**
 * Display-side helper that shortens the verbose
 * `'/Users/.../Herd/bin/php' 'artisan' 'queue-insights:snapshot'`
 * shape Laravel emits via `Event::compileCommand()` into a compact
 * `php artisan queue-insights:snapshot` label.
 *
 * Used in list / row / Tasks-card surfaces. The drilldown modal still
 * shows the full unshortened command verbatim — operators debugging a
 * Laravel scheduler issue still need the absolute binary path.
 *
 * @internal
 */
final class CommandLabel
{
    public static function short(string $command): string
    {
        if ($command === '') {
            return '';
        }

        $tokens = self::tokenise($command);
        if ($tokens === []) {
            return $command;
        }

        // Replace any leading absolute PHP binary with bare `php`. This
        // handles macOS Herd (`/Users/.../Herd/bin/php`), Homebrew
        // (`/opt/homebrew/bin/php`), Docker entries
        // (`/usr/local/bin/php`), and Windows (`C:\php\php.exe`). A
        // versioned suffix (`php8.2`, `php-cli`) is preserved so the
        // operator can still tell at a glance which interpreter the
        // scheduler used.
        $tokens[0] = self::replacePhpBinary($tokens[0]);

        return implode(' ', array_map(self::quoteIfNeeded(...), $tokens));
    }

    /**
     * Tokenise the Symfony-Process-emitted command string into bare
     * (unquoted) tokens. Single-quoted segments are unwrapped so the
     * caller can reason about the underlying value; re-quoting happens
     * at join time only when the value contains a space.
     *
     * @return list<string>
     */
    private static function tokenise(string $command): array
    {
        if (preg_match_all("#'([^']*)'|(\\S+)#", $command, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $out = [];
        foreach ($matches as $match) {
            // Group 1 = single-quoted segment (may be empty between
            // adjacent ''); group 2 = bare token. Exactly one is set per
            // match. Read group 2 first when group 1 is missing or empty
            // so the conditional doesn't depend on offset 1's presence.
            $quoted = $match[1] ?? '';
            $value = $quoted !== '' ? $quoted : ($match[2] ?? '');
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function replacePhpBinary(string $token): string
    {
        // The token is already a single shell argument (the tokeniser
        // unwrapped quoted segments) so the path may contain spaces —
        // `.*?` is fine here, the trailing anchor pins us to the actual
        // binary basename. Recognises macOS / Linux / Windows shapes
        // including versioned (`php8.2`) and `-suffixed` (`php-cli`)
        // variants.
        $pattern = '#^(?:[A-Za-z]:)?[/\\\\].*[/\\\\](php(?:[\d.]*)?(?:-[a-zA-Z0-9]+)?)(?:\.exe)?$#';

        return preg_match($pattern, $token, $m) === 1 ? $m[1] : $token;
    }

    private static function quoteIfNeeded(string $token): string
    {
        return str_contains($token, ' ') ? "'{$token}'" : $token;
    }
}
