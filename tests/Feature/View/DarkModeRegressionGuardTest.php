<?php declare(strict_types=1);

// Phase 5 regression guard. Scans every blade view file in the
// package and flags any that uses a light-mode surface token from
// §3.1 of internal/specs/dashboard-dark-mode.md WITHOUT a paired
// `dark:` companion appearing anywhere in the same file.
//
// File-level (not per-element) granularity is intentional — the
// goal is to catch a contributor adding a new card / row / panel
// in light-only colors. False-positive risk on correctly-paired
// elements (light + dark on the same element) is zero, because
// `str_contains($content, $light)` AND `str_contains($content, $dark)`
// both pass.
//
// Allowlist exists for the two surfaces that are intentionally
// always-light or always-dark and don't take dark variants:
//   * `layouts/app.blade.php` — the `<header class="bg-gray-900">`
//     block is brand chrome that stays Horizon-dark in both modes.
//     Inline `<style>` block also has hardcoded RGB rules. The
//     file as a whole DOES carry dark variants on body + the
//     dual-class JSON colorizer + html.dark-scoped CSS, so we
//     would not skip it for normal tokens; only the bare-light
//     `<header>` block needs allowlisting, and the file-level test
//     handles this naturally — `layouts/app.blade.php` has both
//     `bg-gray-900` (in the always-dark header) AND `dark:bg-gray-900`
//     (the dark surface mapping for cards inside the modal slot).
//
// What this test will catch:
//   - new file with `bg-white` and no `dark:bg-gray-900`
//   - new file with `text-gray-700` and no `dark:text-gray-300`
//   - row partial added without a divider dark variant
//
// What this test will NOT catch (acceptable scope):
//   - mismatched light/dark on the SAME element (e.g. `bg-white`
//     paired with `dark:bg-gray-800` instead of `dark:bg-gray-900`)
//   - light token used on element A and dark variant only present
//     on a separate element B inside the same file (still passes
//     the file-level check). Visual review covers this.

// Reduce a blade source to ONLY the contents of class-bearing attributes
// and Blade conditional class arrays — the contexts where Tailwind tokens
// can actually take effect. Filters out comments, JS strings, prose, etc.
// so the regression guard doesn't get false-pass'd by a token mention in
// a code comment or a `console.log` literal. Matched shapes: class="...",
// x-bind:class="...", :class="...", triggerClass="..." (the hint component
// slot), and Blade `@class` conditional class arrays.
function extractClassAttributeText(string $blade): string
{
    $patterns = [
        '/class="([^"]*)"/',
        '/[a-z-]*[Cc]lass="([^"]*)"/',
        "/[a-z-]*[Cc]lass='([^']*)'/",
        '/x-bind:class="([^"]*)"/',
        '/:class="([^"]*)"/',
        '/@class\(\s*\[(.*?)\]\s*\)/s',
    ];
    $hits = [];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $blade, $m) > 0) {
            $hits = array_merge($hits, $m[1]);
        }
    }

    return implode("\n", $hits);
}

it('every blade view file pairs light surface tokens with their dark companions', function (): void {
    // Each light surface token maps to a list of acceptable `dark:`
    // companions. The same `bg-gray-50` shows up as the body bg
    // (`dark:bg-gray-950`), as a row-hover (`dark:hover:bg-gray-800`),
    // and as a `<pre>` fill (`dark:bg-white/5`). Treating "mapped"
    // as 1-of-N keeps the guard meaningful without forcing the
    // codebase to a single dark target.
    $pairs = [
        'bg-white' => ['dark:bg-gray-900', 'dark:bg-gray-800'],
        'bg-gray-50' => ['dark:bg-gray-950', 'dark:bg-gray-800', 'dark:bg-gray-900', 'dark:bg-white/5', 'dark:hover:bg-gray-800'],
        'bg-gray-100' => ['dark:bg-gray-800'],
        'text-gray-500' => ['dark:text-gray-300', 'dark:text-gray-400'],
        'text-gray-600' => ['dark:text-gray-300'],
        'text-gray-700' => ['dark:text-gray-300', 'dark:text-gray-200'],
        'text-gray-900' => ['dark:text-gray-100'],
        'ring-gray-950/5' => ['dark:ring-white/10', 'dark:ring-white/5'],
        'border-gray-950/5' => ['dark:border-white/10'],
        'divide-gray-950/5' => ['dark:divide-white/10'],
    ];

    // Files where a token check should be skipped — only for surfaces
    // that are intentionally always-light or always-dark. Currently
    // empty: every file with a light token also has a paired dark
    // companion somewhere. Add `'path/relative/to/views/dir' => [...]`
    // entries here if a future surface is genuinely exempt.
    /** @var array<string, list<string>> $allowlist */
    $allowlist = [];

    $viewsDir = realpath(__DIR__ . '/../../../resources/views');
    if ($viewsDir === false || ! is_dir($viewsDir)) {
        $this->fail('resources/views directory not found');
    }

    $files = collectBladeFiles($viewsDir);
    expect(count($files))->toBeGreaterThan(20); // sanity: we found the views

    $violations = [];

    foreach ($files as $absPath) {
        $relPath = ltrim(str_replace($viewsDir, '', $absPath), '/');
        $content = file_get_contents($absPath);
        if ($content === false) {
            continue;
        }

        $exempt = $allowlist[$relPath] ?? [];

        // Restrict the search to class-attribute contexts only — comments,
        // JS strings, prose, and Blade docblocks are stripped. This keeps
        // a code-comment mention of `dark:bg-gray-900` from satisfying
        // the pair check when no actual class element uses it.
        $classText = extractClassAttributeText($content);

        foreach ($pairs as $light => $darkOptions) {
            if ($exempt !== [] && in_array($light, $exempt, true)) {
                continue;
            }

            // Boundary-aware light-token check. We want to count `bg-white`
            // as a class token, NOT as a substring inside `bg-white/10`
            // (alpha modifier) or `dark:bg-white` (legitimate dark).
            // Two anchors: leading character must be one of [space,
            // double quote, single quote, newline] (excludes `:`); trailing
            // anchor matches the same set. Excludes anything starting with
            // `dark:` (already a dark variant) and anything with a `:`
            // prefix (compound like `hover:text-gray-900`).
            $hasLight = preg_match(
                '/(?<![:\-\w\/])' . preg_quote($light, '/') . '(?=[\s"\'<]|$)/',
                $classText,
            ) === 1;

            if (! $hasLight) {
                continue;
            }

            $hasAnyDark = false;
            foreach ($darkOptions as $dark) {
                if (str_contains($classText, $dark)) {
                    $hasAnyDark = true;
                    break;
                }
            }

            if ($hasAnyDark) {
                continue;
            }

            $violations[] = "{$relPath}: has `{$light}` but none of [" . implode(', ', $darkOptions) . '] anywhere in class attributes';
        }
    }

    expect($violations)->toBe([], 'Light-mode surface tokens missing their `dark:` companion. Add the dark variant on the same element, or extend the pair list / allowlist in this test if the surface is intentionally always-light.' . PHP_EOL . PHP_EOL . implode(PHP_EOL, $violations));
});

it('finds and processes a non-trivial number of blade files', function (): void {
    $viewsDir = realpath(__DIR__ . '/../../../resources/views');
    if ($viewsDir === false) {
        $this->fail('resources/views directory not found');
    }

    $files = collectBladeFiles($viewsDir);

    // Sanity check: we expect ~45 blade files in the package. If this
    // suddenly drops, the recursive glob logic broke and the regression
    // guard would silently pass on zero files.
    expect(count($files))->toBeGreaterThan(35);
});

/**
 * @return list<string>
 */
function collectBladeFiles(string $root): array
{
    $files = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}
