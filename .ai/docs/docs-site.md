# Documentation site (VitePress on GitHub Pages) — internals + edit points

AI-facing reference for the published documentation. The package is **docs-site shaped**: `README.md` is a thin landing page and every substantial explanation lives in `docs/`, built by VitePress and deployed to <https://sandermuller.github.io/laravel-queue-insights/>. Read this **before** adding, removing, reordering, or renaming a documentation page.

## Where end-user documentation goes

**In `docs/`, not `README.md`.** The README carries the pitch, badges, install, one Gate snippet, the payload-capture warning, and the link index. Anything longer than a teaser belongs on a page. A new feature gets a section on the page that owns its subsystem, or a new page when it owns a subsystem of its own.

Contributor-facing material — local setup, `composer test` / `composer qa`, the Redis Cluster test lane, PR expectations — goes in `CONTRIBUTING.md`, not on a docs page. The site documents using the package in *your* application; it does not document working on the package. (A testing page would be right if the package shipped test helpers or fakes for consumers. It does not.)

`.ai/docs/*.md` (this directory) is the *other* audience: internals, invariants, and edit points for agents. The two never duplicate each other — each `.ai/docs` file names its end-user counterpart in its opening paragraph.

## Touchpoints — files that own this subsystem

| File | Role |
|---|---|
| `docs/NN-*.md` | The pages. The `NN-` prefix fixes reading order when GitHub renders the folder; VitePress `rewrites` strip it so the published URL is `/{slug}` and stays stable across a reorder. |
| `docs/.vitepress/pages.ts` | **Single source of truth for the page inventory.** Sections, order, sidebar labels, and the `blurb` shown on the "Next" card. `pages`, `slug`, and `link` are derived from it. |
| `docs/.vitepress/config.mts` | Site config: base path, head/OG tags, sitemap, `rewrites` (`home.md` → `index.md`, prefix stripping), the markdown `link_open` rule that strips `NN-` from in-page links at render time, and `buildEnd` (emits `llms.txt`, `llms-full.txt`, and one `<slug>.md` per page). |
| `docs/.vitepress/theme/` | `index.ts` wires `NextPageCta.vue` into the `doc-footer-before` slot and registers `HomeNextSteps.vue`; `custom.css` holds the emerald brand tokens and the widened reading column. |
| `docs/home.md` | The site home (hero + feature grid). Rewritten to `index.md`; **not** the folder index. |
| `docs/README.md` | The GitHub-facing folder index. `srcExclude`d from the site, so it never renders as a page. |
| `docs/public/` | `logo.svg` (favicon + nav mark), `header.png` (OG/Twitter card image, a copy of the repo's `screenshot.png`), `robots.txt`. |
| `CONTRIBUTING.md` | Contributor-facing setup, checks, and PR expectations. Linked from the README's Contributing section. |
| `README.md` — `## Documentation` | The third index surface. Links every page by **absolute published URL**. |
| `.github/workflows/docs.yml` | Builds on any PR touching `docs/**` (the dead-link gate) and deploys to Pages on `main`. Pages source is set to "GitHub Actions". |
| `.gitattributes` | `/docs export-ignore` — the site never ships in the Composer archive. Keep it **outside** the `package-boost (managed)` block. |
| `.gitignore` | `docs/node_modules/`, `docs/.vitepress/cache/`, `docs/.vitepress/dist/`. The whole `/docs` directory must never be re-ignored. |

## Behavioural rules — DO NOT VIOLATE

1. **Three index surfaces must agree on the inventory: `pages.ts`, `docs/README.md`, and the README's `## Documentation` section.** Adding a page means editing all three. `pages.ts` alone drives the sidebar and the Next card, so a page missing from it is reachable only by direct URL.
2. **Links between pages use the `NN-` prefixed filename** (`](10-alerting.md#silencing-noisy-jobs)`), never the published slug. GitHub resolves them as files; the markdown-it rule in `config.mts` strips the prefix at render time so the site resolves them as routes. Using the slug breaks the GitHub view.
3. **README → docs links use absolute published URLs**, never `docs/*.md` paths. `docs/` is `export-ignore`d, so a repo-relative link is dead in the installed vendor copy and on Packagist. The single allowed repo-relative pointer is `[docs/](docs/README.md)` as the source location.
4. **Headings must slugify identically on GitHub and in VitePress.** VitePress collapses consecutive hyphens; GitHub does not. A heading containing ` / ` produces `a--b` on GitHub and `a-b` on the site, so any anchor to it is right in one place and wrong in the other. Use a comma. (`## Push gateway (short-lived workers, CLI)` exists for exactly this reason.)
5. **Renaming a page's slug breaks live URLs.** The `NN-` prefix can change freely — that is what the rewrite is for — but the slug is the published route and is linked from the README, `llms.txt`, and anything external. Rename only with an explicit legacy rewrite or an accepted break.
6. **`npm run build` in `docs/` is the link gate.** VitePress fails the build on a dead internal link. It does **not** check anchors — verify those by grepping the rendered `id=` in `docs/.vitepress/dist/`.
7. **`docs/package-lock.json` is committed.** The workflow runs `npm ci`, which requires it. `composer.lock` being gitignored does not extend to the docs lockfile.
8. **Do not hand-write `llms.txt`.** `buildEnd` generates it from `pages.ts` so it cannot drift from the sidebar.

## What NOT to do

- Don't grow `README.md` back into the manual. It was 1096 lines before the split; anything past a teaser goes on a page and gets linked.
- Don't add a page without a `pages.ts` entry — no sidebar slot, no Next card, no `llms.txt` line.
- Don't put per-page frontmatter descriptions on guide pages. The site has none by design; `blurb` in `pages.ts` is the one place a page describes itself.
- Don't reference `docs/.vitepress/dist/` or `docs/node_modules/` from anything — both are build output and gitignored.
- Don't add a docs page for internals. Design rationale goes in `internal/specs/`; agent-facing invariants go in `.ai/docs/`.
- Don't edit `CHANGELOG.md` to document a docs change. The changelog is generated from release bodies (see `.ai/guidelines/release-automation.md`).
