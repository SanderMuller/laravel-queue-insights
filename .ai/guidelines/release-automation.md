# Release Automation

## CHANGELOG.md is updated automatically — do NOT edit by hand for releases

`CHANGELOG.md` is kept in sync with GitHub releases by `.github/workflows/update-changelog.yml`. When a release is published (not just drafted), the workflow uses `stefanzweifel/changelog-updater-action` to prepend the release body to `CHANGELOG.md` and commits the update back to `main`.

This means:

- **Do not** add changelog entries manually when preparing a release. The release body (drafted in `internal/release-notes-<version>.md` and pasted into the GitHub release) becomes the changelog entry automatically.
- **Do not** include a changelog diff in the release PR — the post-release commit comes from CI.
- If the changelog needs a fix *after* a release, edit `CHANGELOG.md` directly and commit — but this is unusual and only for typos or formatting issues in the auto-generated entry.

## Release workflow (summary)

1. Commit and push the code to `main`
2. Draft release notes in `internal/release-notes-<version>.md` (gitignored — the file stays local)
3. Tag and create the GitHub release with the release-notes file as the body
4. CI automatically prepends the release body to `CHANGELOG.md` and commits it back to `main`

`update-changelog` is the only workflow that runs on release publish. Nothing decorates the release body, so it is published exactly as written.

No manual `CHANGELOG.md` edits are part of the release PR.
