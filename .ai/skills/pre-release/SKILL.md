---
name: pre-release
description: "Pre-push / pre-release checklist for laravel-queue-insights. Runs Rector, Pint, Pest, PHPStan, audits README + `.ai/` docs for staleness, then commits + pushes, watches CI for green, and drafts release notes against the verified SHA. Activate before: pushing to remote, tagging a release, writing release notes, or when user mentions: pre-release, pre-push, release checklist, ship, cut release, release notes."
---

# Pre-Release Checklist

Run this gauntlet before pushing commits that may be tagged as a release. It catches regressions the two-tier `backend-quality` skill skips — Rector drift, stale docs shipped to downstream projects via `package-boost:sync` — then gates the release on CI-matrix green and pins a verified SHA into the release notes.

## When to Use This Skill

Activate when:
- About to push commits that will land in a release
- About to write or update release notes
- User says "ship it", "cut a release", "pre-push", "release checklist"
- A feature/fix is fully implemented and quality-gated

Do NOT use mid-development — this is a completion-level skill.

**The user cuts the tag, not you.** The user runs `gh release create` (or the GitHub UI) themselves — tagging is irreversible-ish and a release-visibility decision the user owns. Do NOT execute tag/release-create commands. Once steps 1-7 are done and the release notes file exists with a green-CI SHA pinned, report "ready to tag" and stop. The skill's job ends only once step 8b (post-tag watch) has confirmed the tag-ref + release-event workflows are green — "tag cut" is not the finish line.

## Workflow

Run **in this order**. Each must pass before moving to the next. Fix issues as they surface; do not batch.

Always append `|| true` to verification commands so output is captured even on failure (per repo `CLAUDE.md` rule). Pass/fail is determined from the captured output, not the exit status alone.

**The full order is 1 → 2 → 3 → 4 → 5 → commit → push → 6 → 7 (draft notes) → user cuts tag → 8a (pre-tag gate) → 8b (post-tag watch).** Do not jump from local checks straight to drafting release notes. The release-notes file is written only after the changes have been committed, pushed, and CI is green on that exact SHA. Notes claim "tests pass on the CI matrix"; CI must have produced that fact first.

### 1. Rector

```bash
vendor/bin/rector process || true
```

Must report **0 files changed**. If Rector modifies files, review the diff, commit the changes (or fold them into the release commit), and re-run until clean.

### 2. Pint

```bash
vendor/bin/pint --dirty --format agent || true
```

Must be clean. Re-run after Rector — Rector fixes can introduce style drift.

### 3. Full Test Suite

```bash
vendor/bin/pest || true
```

Must show 0 failures.

**Local green ≠ CI green.** Local Pest runs one OS/PHP/Laravel combo; the CI matrix spans Windows + multiple PHP/Laravel + `prefer-lowest`/`prefer-stable` cells. Step 6 (CI gate) is the authoritative test boundary across the matrix. Use `ci-matrix-troubleshooting` when a matrix cell goes red.

### 4. PHPStan

```bash
vendor/bin/phpstan analyse --memory-limit=2G || true
```

Must show 0 errors. Fix real issues — do not pad the baseline. See `backend-quality` skill for baseline rules.

### 5. Documentation freshness audit

Release-worthy features change user-visible behavior, so `README.md` and the `.ai/` files we ship to downstream projects (via `package-boost:sync`) can drift silently. Every release must audit both.

**Rule:** add or edit docs only where they reflect a real change. Do not bloat the README or skills. Delete stale content aggressively.

#### 5a. README

Scan `README.md` against the commits in this release (`git log <last-tag>..HEAD`). Update the **Features** list and any feature subsections that gained behavior in this release:

- **Live counts / queue panel** — new metric or grouping change.
- **Pending & delayed jobs** — inspector behavior, opt-out semantics.
- **Batches** — chip behavior, modal navigation, retry caveats, opt-out.
- **Chained jobs** — chip / Chain section behavior, payload-source notes.
- **Failed jobs / Retry flow** — gate semantics, bulk-retry rules.
- **Customising row markup** — list of publishable partials.
- **Public API signatures** on `QueueInsights`, listeners, or `Support/*` classes — if a method gained a parameter, a new public method was added, or behavior changed.

If unsure whether a change warrants a README update: would a user reading the README *after* the release see outdated advice? If yes, update.

Do NOT edit `CHANGELOG.md` — `.github/workflows/update-changelog.yml` prepends the release body automatically on publish.

#### 5b. Laravel Boost skills + guidelines

`.ai/skills/` and `.ai/guidelines/` are synced by Laravel Boost (`vendor/bin/testbench package-boost:sync`) to `CLAUDE.md`, `AGENTS.md`, `.claude/skills/`, and `.github/skills/`. Those generated files ship with the package and are read by downstream projects' AI tooling.

If anything in `.ai/` changed (or you suspect generated files have drifted from sources), sync and verify:

```bash
vendor/bin/testbench package-boost:sync || true
git status --short .claude/ .github/ CLAUDE.md AGENTS.md
```

All generated files must be committed together with their `.ai/` sources (per the `ai-guidelines` skill). If sync reports `total: N unchanged` for every category, nothing to commit.

### Commit + push

After all 1-5 pass, commit the release-worthy changes. Stage by explicit paths (never `git add -A`) so secrets or stray binaries can't slip in. Use Conventional Commits (`feat(scope): …`, `fix(scope): …`, `chore(scope): …`, `docs(scope): …`) — match the recent log style (`git log --oneline -10`).

```bash
git add <paths>
git commit -m "<conventional message>"
git push origin main
```

If the local branch is behind `origin/main` (CI's `update-changelog` workflow commits back), `git pull --rebase origin main` after committing local work — the changelog commit doesn't conflict with source code.

### 6. CI green-light gate (after push, before release notes)

Local green ≠ CI green. Matrix cells frequently catch env-shape bugs (missing `APP_KEY`, prefer-lowest peer constraints, Windows fs races) that local dev never sees. A green tag on a red CI is a broken release.

**Scope is per-commit, not per-run.** Multiple workflows have opinions about the same SHA. `gh run watch` follows a single run and silently skips the others. Enumerate by commit SHA and wait for every matching run:

```bash
SHA=$(git rev-parse HEAD)

# Settle — GitHub takes several seconds to register runs against the new SHA.
# Querying too early returns an empty list; running=0 would falsely signal green.
# Use the Bash tool with run_in_background=true if you want to do other work
# while waiting; do NOT chain shorter sleeps to work around the harness sleep cap.
until [ "$(gh run list --commit "$SHA" --json databaseId -q 'length')" -gt 0 ]; do sleep 5; done

# List every workflow run tied to this SHA
gh run list --commit "$SHA" --json databaseId,name,event,status,conclusion

# Wait for every run to reach a terminal state
while true; do
    total=$(gh run list --commit "$SHA" --json databaseId -q 'length')
    running=$(gh run list --commit "$SHA" --json status -q '[.[] | select(.status != "completed")] | length')
    [ "$total" -gt 0 ] && [ "$running" -eq 0 ] && break
    sleep 15
done

# Then assert all success/skipped
failed=$(gh run list --commit "$SHA" --json conclusion,name -q '[.[] | select(.conclusion != "success" and .conclusion != "skipped")] | length')
[ "$failed" -eq 0 ] || { echo "CI red on $SHA"; gh run list --commit "$SHA"; exit 1; }
```

Pass criteria: every run for this commit has `conclusion` in `{success, skipped}`. Skipped is fine — path-filtered workflows are expected to skip when the release commit touches docs only.

**Don't rely on a "latest run" heuristic.** `gh run list --branch main --limit 1` may pick a run from a completely different push — the commit-SHA filter is the only reliable anchor.

On failure:

1. Pull the failure log via `gh run view <id> --log-failed` (or `gh api /repos/<owner>/<repo>/actions/jobs/<job-id>/logs` if `--log-failed` is empty).
2. Reproduce locally — often requires the same env shape as CI (blank `APP_KEY`, clean composer install, specific PHP/Laravel combo). Use `ci-matrix-troubleshooting` for matrix-cell-specific issues.
3. Fix with a new commit on the same branch.
4. Push and re-run step 6 against the new HEAD.

**Do NOT write release notes until CI is green.** Release notes claim CI-matrix facts; CI is the evidence.

**Workflows triggered by `release` (e.g. `release-benchmark`, `update-changelog`)** run AFTER tag creation — they're outside this gate by design and are watched in step 8b.

**`on: push` workflows re-fire on the tag-ref push** (creating a release pushes a tag ref). Those re-fire runs are watched in step 8b too — they sometimes catch environment-shape failures that the main-branch run narrowly missed.

### 7. Release notes (ONLY after step 6 CI-green)

This is where agents most commonly slip: running the local gauntlet, then jumping straight to `Write internal/release-notes-<version>.md` without committing, pushing, or watching CI. **Do not do that.**

#### Choose the version

Latest tag: `gh release list --limit 1 --json tagName -q '.[0].tagName'`. The package follows semver while pre-1.0:

- New user-visible feature, additions to public API → minor bump (`0.3.0` → `0.4.0`)
- Bug fix only / docs / refactor → patch bump (`0.3.0` → `0.3.1`)
- Breaking change to public API (renamed config key, removed method) → minor bump pre-1.0 (with a clear `BREAKING:` line in the notes)

#### Public-artifact rules

Release notes flow directly to the public GitHub release + `CHANGELOG.md` and are indexed by Packagist. Anything written here is visible to every downstream consumer.

**Do NOT write:**
- Peer / instance / channel framing: ~~"sourced from peer `e0cp6lq3`"~~, ~~"via claude-peers dogfood"~~
- Claude-Code-internal phrasing: ~~"agent-driven"~~, ~~"via the rector companion peer"~~
- Any 8-character alphanumeric sequence that looks like a peer ID

**Write instead:**
- Generic adoption framing: "sourced from production dogfood", "real-world adoption feedback"
- Named public contributors only (GitHub usernames, named downstream apps that consented to credit). Otherwise stay generic.
- Technical reasoning (why the decision was made) without tying it to an internal session.

Internal planning files (`internal/specs/*.md`) MAY reference peer IDs — they stay out of git history (`internal/` is gitignored). Only the release-notes file under `internal/release-notes-*.md` is under the public-artifact rule, since its body is what the user copies into the GitHub release.

**Quick scrub before `Write`ing:** grep your draft for `peer`, `claude-peers`, `claude-code`, and any `[a-z0-9]{8}` sequence. Rewrite or delete if present.

#### Preflight — three checks before `Write`

```bash
# 1. Working tree must be clean
git status --short || true

# 2. HEAD must be pushed
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && echo "pushed" || echo "NOT pushed"

# 3. Every CI run for this SHA must be terminal + {success, skipped}
SHA=$(git rev-parse HEAD)
gh run list --commit "$SHA" --json name,status,conclusion
```

Only when (1) status is empty, (2) echoes `pushed`, and (3) every run is `completed` + `{success, skipped}` may you `Write internal/release-notes-<version>.md`.

#### Notes file format

```markdown
<!-- verified-sha: <full 40-char SHA from git rev-parse HEAD> -->

# <version>

<one-paragraph summary — what's the headline?>

## What's new

- **<feature>** — what it does, why it matters. One bullet per user-visible feature.

## Bug fixes

- <fix> — what was broken and how it was caught (link to spec / issue if external).

## Notes

- BREAKING: ... (only if applicable)
- Migration steps for existing installs (only if needed)
```

**Pin the verified SHA in the very first line.** GitHub strips HTML comments when rendering the release body, so this is invisible to readers but greppable by step 8a:

```
<!-- verified-sha: aab58d2aa7c6e3496d0eece82c18566e21a2e70a -->
```

The SHA is the exact `git rev-parse HEAD` step 6 proved green. Step 8a's pre-tag gate fails closed if the notes-file SHA does not match the live remote tip (i.e. someone landed more commits between draft and tag).

#### Match the existing tone

Read the previous release notes in `internal/release-notes-*.md` for tone, structure, and length. The package's notes lean concise — bullets over paragraphs, technical reasoning where it changes behavior, no marketing language.

#### CI handles two things automatically — do not do them manually

- **`CHANGELOG.md`** is prepended with the release body by `.github/workflows/update-changelog.yml` on release publish.
- If the package ships any benchmark workflow that decorates the release body, do not paste benchmark numbers manually — let CI fill the markers.

Once the file is written, report "ready to tag" with the SHA and the version, and stop. The user takes it from there.

### 8. Pre-tag gate + post-tag watch

Step 6 proves CI green at draft time. Step 8 proves CI is *still* green at tag time and catches failures that only show up on the tag-ref push.

#### 8a. Pre-tag gate

The user runs this immediately before `gh release create` (in their terminal — not the agent's). It re-verifies HEAD hasn't drifted, the notes file pins this exact SHA, and CI is still green.

```bash
SHA=$(git rev-parse HEAD)
VERSION="<version>"  # e.g. 0.4.0
NOTES="internal/release-notes-${VERSION}.md"

# A. Notes file pins this SHA
grep -qE "^<!-- verified-sha: $SHA -->$" "$NOTES" || { echo "NOTES SHA DRIFT — HEAD=$SHA, notes say $(grep verified-sha "$NOTES")"; exit 1; }

# B. HEAD matches the LIVE remote tip — not the cached tracking ref.
#    `ls-remote` always hits the remote; `git rev-parse origin/main` is stale until fetch.
LIVE_TIP=$(git ls-remote origin refs/heads/main | awk '{print $1}')
[ "$SHA" = "$LIVE_TIP" ] || { echo "HEAD DRIFT — HEAD=$SHA live origin/main=$LIVE_TIP"; exit 1; }

# C. Every CI run for this SHA still terminal + {success, skipped}
failed=$(gh run list --commit "$SHA" --json conclusion -q '[.[] | select(.conclusion != "success" and .conclusion != "skipped")] | length')
running=$(gh run list --commit "$SHA" --json status -q '[.[] | select(.status != "completed")] | length')
[ "$running" -eq 0 ] && [ "$failed" -eq 0 ] || { echo "CI NOT GREEN — running=$running failed=$failed"; gh run list --commit "$SHA"; exit 1; }

echo "OK to tag $VERSION at $SHA"
```

The `ls-remote` call is the key difference from the step 7 preflight (which uses the local tracking ref). Step 7 runs right after push when the tracking ref is fresh; step 8a can run minutes or hours later, and the only safe way to prove HEAD is still the tip is to hit the remote.

If any check fails, do NOT tag. Fix the drift, re-run steps 6-7 against the new SHA, then retry 8a.

#### 8b. Post-tag watch

Creating the release pushes a tag ref, which re-fires `on: push` workflows (e.g. `run-tests`) against that ref. Watch those runs — they're not part of the pre-tag gate and can fail even when 8a passed (Windows fs races, prefer-lowest combos that narrowly missed the main-branch run). Also watch `release`-event decorators (`update-changelog`, anything else triggered on `release: published`).

**Do not use `gh run list --branch "$TAG"`.** The `--branch` flag's semantics for tag refs are undocumented — sometimes works, sometimes returns empty. The reliable selector is the tag's commit SHA plus a jq filter on `headBranch == $TAG`. Both `push`-event (tag-ref re-fire) and `release`-event runs attach to that SHA with `headBranch` set to the tag name.

**Run 8b strictly after `gh release create` has completed.** The tag must already exist on the remote; fetch it locally before resolving the SHA.

```bash
TAG="$VERSION"
git fetch --tags origin --quiet
TAG_SHA=$(git rev-list -n 1 "$TAG")

# Wait until every tag-scoped run is terminal
waited=0
while [ "$waited" -lt 900 ]; do
    running=$(gh run list --commit "$TAG_SHA" --json status,headBranch \
      -q "[.[] | select(.headBranch == \"$TAG\") | select(.status != \"completed\")] | length")
    total=$(gh run list --commit "$TAG_SHA" --json databaseId,headBranch \
      -q "[.[] | select(.headBranch == \"$TAG\")] | length")
    [ "$total" -gt 0 ] && [ "$running" -eq 0 ] && break
    sleep 15
    waited=$((waited + 15))
done
[ "$total" -gt 0 ] || { echo "NO TAG-REF RUNS after ${waited}s — investigate"; exit 1; }

failed=$(gh run list --commit "$TAG_SHA" --json conclusion,headBranch,name \
  -q "[.[] | select(.headBranch == \"$TAG\") | select(.conclusion != \"success\" and .conclusion != \"skipped\")] | length")
[ "$failed" -eq 0 ] || { echo "TAG-REF CI RED on $TAG ($TAG_SHA)"; gh run list --commit "$TAG_SHA"; exit 1; }
```

If red:
1. Investigate via `gh run view <id> --log-failed`.
2. If the failure reveals a real bug (not just flake): fix on `main`, cut a patch release. Do not rewrite the tag.
3. If `update-changelog` failed: `CHANGELOG.md` won't be prepended — re-run the workflow once the underlying cause is fixed, or prepend the entry manually (rare).

**Rule:** the skill is not done until 8b goes green. "Tag cut" is not the finish line; "tag-ref CI green + release-event workflows green" is.

## Quick Reference

| Step              | Command                                                                                  | Pass criteria                                 |
|-------------------|------------------------------------------------------------------------------------------|-----------------------------------------------|
| 1. Rector         | `vendor/bin/rector process \|\| true`                                                    | 0 files changed                               |
| 2. Pint           | `vendor/bin/pint --dirty --format agent \|\| true`                                       | clean                                         |
| 3. Tests          | `vendor/bin/pest \|\| true`                                                              | 0 failures                                    |
| 4. PHPStan        | `vendor/bin/phpstan analyse --memory-limit=2G \|\| true`                                 | 0 errors                                      |
| 5a. README        | manual scan vs `git log <last-tag>..HEAD`                                                | no stale claims; new behavior listed          |
| 5b. Boost docs    | `vendor/bin/testbench package-boost:sync \|\| true`                                      | `.ai/` ↔ generated files in sync              |
| **commit + push** | `git add <paths>` → `git commit` → `git push origin main`                                | HEAD pushed to `origin/main`                  |
| 6. CI green-light | `gh run list --commit "$(git rev-parse HEAD)"` all complete + no failure                 | every run for the SHA in `{success, skipped}` |
| 7. Release notes  | preflight (clean tree + pushed + CI green) → `Write internal/release-notes-<version>.md` | first line is `<!-- verified-sha: $SHA -->`   |
| 8a. Pre-tag gate  | one-liner asserts notes-SHA, live-remote tip, CI-still-green before `gh release create`  | prints `OK to tag`                            |
| 8b. Post-tag watch | `gh run list --commit "$TAG_SHA"` filtered by `headBranch == $TAG`                      | tag-ref + release-event workflows all green   |

## Important

- Run every step, in order, even if nothing "release-worthy" looks changed. Seemingly unrelated refactors have historically introduced subtle behavior shifts that only the matrix catches.
- Do not push if any step 1-5 fails. Fix, then restart from step 1 — earlier steps may re-break after a later fix.
- Steps 5a and 5b are the most common source of silent drift — the README and shipped skills are read by downstream users, and bloat accumulates fast. Delete stale content before adding new.
- Step 6 (CI gate) is non-skippable: CI runs against a clean env (no ambient `APP_KEY`, no cached auth user, fresh composer install) and frequently catches env-shape bugs that local dev never sees. Waiting 2 minutes for CI green is cheaper than tagging a broken release.
- Step 7 (release notes) is gated by step 6 — **the release-notes file must not exist on disk until CI is green on the pushed commit.** If the step-7 preflight fails any of its three conditions, the draft is premature; go back to whichever earlier step is incomplete.
- Step 8a re-verifies the live remote tip (`git ls-remote`, not the cached `origin/main`) so a concurrent push can't slip a stale commit through. 8b uses `--commit "$TAG_SHA"` + `headBranch == $TAG` (not `--branch "$TAG"`) so the tag-ref `on: push` re-fires and `on: release` decorators are both caught. Run both every time, even for one-commit patch releases.
- `pest --parallel` on Windows `prefer-lowest` has a known FS race in `PackageManifest::write()` → `rename()`. Local parallel-pest green does not prove CI-matrix green. Steps 6 and 8b are the authoritative test gates.
