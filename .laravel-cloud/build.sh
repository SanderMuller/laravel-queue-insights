#!/usr/bin/env bash
#
# Laravel Cloud build script for the queue-insights demo.
#
# Cloud detects this repo as a Laravel app via the existing root
# `composer.lock` (the package's dev lockfile — kept untouched). After
# checkout, Cloud runs this script before `composer install`. The script
# promotes the `demo/` Laravel skeleton to the new repo root and stashes
# the package source under `./package/` so Composer's path repository can
# still resolve `sandermuller/laravel-queue-insights` from there.
#
# Cloud build command (configure in Cloud UI):
#   bash .laravel-cloud/build.sh && composer install --no-dev --no-interaction --optimize-autoloader
#
# Local smoke-test:
#   git clone <repo> /tmp/demo-test && cd /tmp/demo-test
#   bash .laravel-cloud/build.sh
#   composer install --no-dev --no-interaction --optimize-autoloader
#   php artisan route:list   # must include `/`

set -euo pipefail

# extglob enables the `!(...)` glob negation; dotglob makes `*` include
# dotfiles so we don't strand `.env.example`, `.gitignore`, etc.
shopt -s dotglob extglob

mkdir -p package

# Stash the package source + workbench + everything else under ./package/
# so the demo skeleton can take the new root cleanly. Negation keeps
# `demo/`, `package/`, and `.git/` in their current locations.
mv !(demo|package|.git) package/

# Promote demo to the new root.
mv demo/* ./
mv demo/.[!.]* ./ 2>/dev/null || true
rmdir demo

# The lockfile demo/ shipped with records the path-repo url as `..`,
# which is no longer the package location after the move. Drop it so
# `composer install` regenerates against the rewritten composer.json.
# Determinism cost is acceptable for a demo deploy.
rm -f composer.lock

# Rewrite the demo composer.json (now ./composer.json) so:
#   - the path-repo url points at the moved package source (./package)
#   - the regular psr-4 autoload entry for Workbench\App\ points at the
#     moved workbench dir (./package/workbench/app/) instead of the
#     local-dev relative path (../workbench/app/)
php -r '
    $f = "composer.json";
    $j = json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
    // Index-based loop (not foreach-by-reference): the ?? operator returns
    // by value, so `foreach ($j["repositories"] ?? [] as &$r)` would write
    // into a temporary copy and the change would not persist into $j.
    foreach (array_keys($j["repositories"] ?? []) as $i) {
        $r = $j["repositories"][$i];
        if (($r["type"] ?? "") === "path" && ($r["url"] ?? "") === "..") {
            $j["repositories"][$i]["url"] = "./package";
        }
    }
    // The PSR-4 key in JSON is `Workbench\App\` — one backslash between
    // segments. In a PHP single-quoted string that is `\\`. Inside this
    // shell single-quoted -r argument that is also `\\` (shell single
    // quotes are literal — no shell-level escape doubling needed).
    if (isset($j["autoload"]["psr-4"]["Workbench\\App\\"])) {
        $j["autoload"]["psr-4"]["Workbench\\App\\"] = "./package/workbench/app/";
    }
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

echo "build.sh: layout ready for composer install --no-dev"
