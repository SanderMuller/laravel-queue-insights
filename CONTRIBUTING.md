# Contributing

Issues and pull requests are welcome at
[github.com/SanderMuller/laravel-queue-insights](https://github.com/SanderMuller/laravel-queue-insights).

## Getting set up

```bash
git clone https://github.com/SanderMuller/laravel-queue-insights.git
cd laravel-queue-insights
composer install
```

There is no host application — the package boots a Laravel kernel through
[Orchestra Testbench](https://packages.tools/testbench). Run artisan-style
commands with `vendor/bin/testbench`, never `php artisan`.

Most of the suite needs a reachable Redis. It defaults to `127.0.0.1:6379`
database `15` with the `predis` client; override with `REDIS_HOST`,
`REDIS_PORT`, `REDIS_DB`, and `QI_REDIS_CLIENT` (`predis` or `phpredis` — CI
runs both).

## Running the checks

```bash
composer test   # Pest suite
composer qa     # Rector, Pint, PHPStan
```

Both must pass before you open a pull request. Individual tools are available as
`composer rector`, `composer format`, and `composer phpstan`.

`composer qa` needs **PHP 8.4 or newer**, even though the package itself supports
8.3. `tomasvotruba/type-coverage` 2.3 requires 8.4, and on 8.3 Composer resolves
2.2.x, which does not understand the `type_perfect` keys in `phpstan.neon.dist` —
PHPStan then fails while loading its configuration. The test suite has no such
requirement and runs on 8.3; CI analyses on 8.4.

### Redis Cluster tests

The `cluster` group skips itself unless `REDIS_CLUSTER_HOST` is set and
reachable, so a normal `composer test` never runs it. To exercise it you need a
real cluster — CI uses `grokzen/redis-cluster` (3 masters, 3 replicas) alongside
a standalone Redis for the `default` connection:

```bash
REDIS_CLUSTER_HOST=127.0.0.1 REDIS_CLUSTER_PORT=7000 \
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 REDIS_DB=15 \
vendor/bin/pest --group=cluster
```

## Documentation

`README.md` is a thin landing page. User-facing documentation lives in
[`docs/`](docs/README.md), a VitePress site published to
<https://sandermuller.github.io/laravel-queue-insights/>. A change in behaviour
belongs on the page that owns its subsystem, not in the README.

```bash
cd docs && npm ci && npm run build
```

The build fails on a dead internal link, so run it before pushing a docs change.
Adding a page means updating three places: `docs/.vitepress/pages.ts`,
`docs/README.md`, and the README's Documentation section.

## Changelog

Don't edit `CHANGELOG.md`. It is written by
`.github/workflows/update-changelog.yml` from the GitHub release body when a
release is published.

## Pull requests

- One concern per pull request.
- Add tests alongside any behavioural change — the suite is the specification.
- Follow the surrounding code style; `composer format` settles the rest.
- Public API changes are semver-governed. Say so in the description if you
  change a signature, a config key, or a published asset path.

## Security

Please review the [security policy](SECURITY.md) before reporting a
vulnerability. Don't open a public issue for one.
