<?php declare(strict_types=1);

/**
 * Locks the `.gitattributes` export-ignore invariants for paths that must
 * NOT ship in the Packagist tarball. The hosted demo (`demo/`), the
 * workbench preview wiring (`workbench/`), and the Cloud build script
 * directory (`.laravel-cloud/`) are all dev-only — Composer consumers
 * pulling the package as a dependency must not pay for them.
 *
 * Reads `.gitattributes` directly rather than running `composer archive`
 * because the latter is slow (multi-second subprocess) and the export
 * behaviour is fully determined by the gitattributes content.
 */
it('exports-ignores demo, workbench, and .laravel-cloud from the package tarball', function (): void {
    $contents = file_get_contents(__DIR__ . '/../../.gitattributes');

    expect($contents)->toBeString();

    $required = [
        '/demo',
        '/workbench',
        '/.laravel-cloud',
    ];

    foreach ($required as $path) {
        expect($contents)
            ->toMatch('/^' . preg_quote($path, '/') . '\s+export-ignore\s*$/m');
    }
});
