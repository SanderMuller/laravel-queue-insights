# Structured Details Modal

## Overview

Replace the raw `<pre>json_encode(...)</pre>` body in the dashboard's Details modal with a Horizon-style structured layout: grouped sections, humanized values, pills/stat cards, and tier-specific rendering keyed off `capture.payloads`. Backend shape is already sufficient (`$selectedPayload` carries baseFields + stream `_id` + sanitizer output); this is pure template work plus a small inline JSON colorizer.

Drives first-enable UX: every consumer hits the "I flipped capture on, what does it look like?" moment. A structured view makes the difference between "nice" and "usable" and shouldn't require each consumer to fork the published view.

---

## 1. Current State

`resources/views/dashboard.blade.php:194-224` renders the modal. Since `f8384b0`:

- Header carries a capture-mode badge (`capture: off|metadata|full`).
- Body is a single `<pre>` with `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` — flat, monospace, unstructured.
- Footer shows tier-specific escalation copy for `off` and `metadata` modes; no footer on `full`.
- `$selectedPayload` is an `array<string, string>` from `QueueInsights::recentCompleted`. Keys are:
  - Always: `_id`, `class`, `connection`, `queue`, `duration_ms`, `attempts`, `processed_at`
  - Under `metadata`: `payload_displayName`, `payload_maxTries`, `payload_timeout`, `payload_backoff`, and for closure/encrypted jobs `payload_note='payload_not_persisted'`
  - Under `full`: arbitrary `payload_*` keys per the bound `PayloadSanitizer`, plus the `metadata` fields

## 2. Proposed Changes

Three structured sections. Each section renders unconditionally when its data is present; tier gating is implicit in which keys exist on `$selectedPayload` (off → only base; metadata → base + job-config; full → base + job-config + raw sanitized payload).

### Section A: Base metadata (always visible)

Fields from the seven base keys + `_id`.

| Label | Source | Render |
|---|---|---|
| Class | `class` | Monospace, wrapping allowed, full FQCN |
| Connection | `connection` | Tag pill (gray, same style as queue-card driver badge) |
| Queue | `queue` | Tag pill |
| Duration | `duration_ms` | `CarbonInterval::milliseconds((int) $v)->cascade()->forHumans(['short' => true])` + raw `"(1243 ms)"` in gray |
| Attempts | `attempts` | Badge — amber bg if `(int) $v > 1`, gray if `=== 1`; empty string → `—` |
| Processed at | `processed_at` | ISO string + `CarbonImmutable::parse($v)->diffForHumans()` in gray |
| Stream ID | `_id` | Monospace, small, with a copy-to-clipboard affordance |

Use a two-column `<dl>` for label/value layout (matches Tailwind CDN idioms already in use).

### Section B: Job config (visible when `payload_displayName` key present)

Renders under `capture.payloads=metadata` and `full`. Source: `MetadataOnlySanitizer` output.

- `displayName` row — monospace single line
- Stats row of three labeled cards: `maxTries` / `timeout` / `backoff`. Each card: label on top, value below, fallback `—` if unset
- If `payload_note === 'payload_not_persisted'`: **replace** the stats row with a yellow info box: "Payload not persisted — closure or encrypted job." No job-config stats possible for these.

### Section C: Payload (visible when any `payload_*` key beyond the metadata set exists)

Renders under `capture.payloads=full`. Two-tab toggle inside the section:

- **Sanitized JSON** (default): pretty-printed JSON of the `payload_*` keys (minus the metadata ones which live in Section B). Syntax-highlighted via a small inline JS colorizer (see section 3).
- **Raw fields**: KV table of the same data, one row per `payload_*` key. For long values, truncate to 200 chars with a "…" affordance (click to expand).

Tab state is component-local (`$payloadTab = 'json'` or `'raw'`), defaults to `'json'` on `openPayload`. Persist across poll cycles via a public Livewire property.

### Footer

Keep the f8384b0 tiered hints exactly as-is for `off` and `metadata`. No footer on `full` (nothing more to escalate to).

## 3. Inline JSON colorizer

Pick **inline ~40-line JS** over a CDN (Prism/highlight.js). Rationale:

- Keeps the package's CDN count at 1 (Tailwind). Adding Prism adds a second third-party fetch per dashboard load.
- Works offline / in air-gapped environments (some consumer Vapor deployments proxy outbound traffic).
- The colorizer only needs to handle pretty-printed JSON output — no multi-language grammar needed.
- Zero new failure modes from third-party JS semver drift.

Implementation sketch (placed inline in `layouts/app.blade.php` or emitted inside the modal wrapper):

```js
function highlightJson(src) {
    return src
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(
            /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
            (m, str, colon, kw, num) => {
                if (str) return colon
                    ? `<span class="text-blue-700">${str}</span>${colon}`
                    : `<span class="text-green-700">${str}</span>`;
                if (kw)  return `<span class="text-purple-700">${kw}</span>`;
                if (num) return `<span class="text-orange-700">${num}</span>`;
                return m;
            },
        );
}
```

Wire up on Livewire's `livewire:initialized` event and re-run on `livewire:update` (polls may re-render the modal). Target `[data-json-highlight]` nodes; source is their textContent, output replaces innerHTML.

## 4. Component / Livewire API changes

Minimal:

- New public property `public string $payloadTab = 'json'` on `QueueInsightsDashboard`.
- New method `public function setPayloadTab(string $tab): void` — in `['json', 'raw']` allowlist (same pattern as `setHistoryMetric`).
- `openPayload()` resets `$payloadTab = 'json'` on each open.
- `closePayload()` already nulls `$selectedPayloadId`; no change.
- `render()` passes `$payloadTab` to the view.

Computed in the Blade helper scope (not on the component — these are pure view concerns):

- `$baseKeys = ['class', 'connection', 'queue', 'duration_ms', 'attempts', 'processed_at']`
- `$metadataKeys = ['payload_displayName', 'payload_maxTries', 'payload_timeout', 'payload_backoff', 'payload_note']`
- `$payloadKeys = array_diff(array_keys($selectedPayload), [...$baseKeys, ...$metadataKeys, '_id'])`

## 5. Accessibility + interaction

- Modal wrapper already has `wire:click="closePayload"` on backdrop + `wire:click.stop` on content — keep.
- Copy-to-clipboard on Stream ID: small button with `aria-label="Copy stream id"`, uses `navigator.clipboard.writeText`. Fall back to selecting text if clipboard unavailable.
- Tab toggle: semantic `<button>` elements, `aria-selected="true|false"`, `role="tab"`.
- Dates: both raw ISO and humanized present, no information hiding for copy/parse tooling.

## 6. Out of scope (explicit)

- Clickable Class → `selectClass`. Row in the table above is already clickable; doubling the interaction doubles the keyboard/accessibility semantics work for zero new capability. Revisit if there's a real use case (e.g. "filter by class" from inside the modal without closing it).
- Job timeline / retry history. Would need new backend reads (replay the per-class stream, cross-reference failed_jobs). Separate spec.
- Payload diffing between attempts. Same reason.
- Syntax highlighting theme toggle. Single theme (Tailwind default palette) matches the rest of the dashboard.

## 7. Testing

Feature tests (Livewire, `tests/Feature/Http/DashboardComponentTest.php`):

- Base metadata section renders for all three modes (off/metadata/full) — assert class/connection/queue/duration/attempts/processed-at/stream-id all present.
- Job config section **absent** under off; **present** with three stat cards under metadata/full; yellow info box replaces stats when `payload_note` is set.
- Payload section **absent** under off and metadata; present under full; default tab is JSON; `setPayloadTab('raw')` flips the view.
- `setPayloadTab` with invalid input is a no-op (consistent with `setHistoryMetric`).
- `openPayload` resets `$payloadTab` to `'json'` even if a prior open left it on `'raw'`.

Visual regression (manual for now): open each tier in a browser via the dogfood symlink on hihaho.

## Implementation

### Phase 1: Component + view restructure (Priority: HIGH)

- [ ] Add `public string $payloadTab = 'json'` to `QueueInsightsDashboard`
- [ ] Add `setPayloadTab(string $tab): void` with `['json', 'raw']` allowlist
- [ ] Reset `$payloadTab` in `openPayload()` to `'json'`
- [ ] Pass `$payloadTab` to the view in `render()`
- [ ] Split `dashboard.blade.php` modal body into Section A / B / C per shape above
- [ ] Add `$baseKeys` / `$metadataKeys` / `$payloadKeys` derivations at the top of the modal block
- [ ] Tests — component properties and tab allowlist/reset semantics

### Phase 2: Presentation (Priority: HIGH)

- [ ] Section A: two-column `<dl>` with pills, humanized duration, diffForHumans timestamp, stream-id + copy button
- [ ] Section B: displayName row + stats-of-three + yellow `payload_not_persisted` branch
- [ ] Section C: tab header (`role="tab"` + `aria-selected`) + JSON pane + raw KV pane (truncation at 200 chars with expand)
- [ ] Inline JS JSON colorizer in `layouts/app.blade.php` (~40 LOC, no external dep)
- [ ] Copy-to-clipboard JS snippet with `navigator.clipboard` + text-selection fallback
- [ ] Tests — tier-keyed presence of each section + tab switching + closure/encrypted branch

### Phase 3: Polish + accessibility (Priority: MEDIUM)

- [ ] `aria-label`s on copy button + tab toggles
- [ ] Keyboard handling: Esc closes modal (already supported by Livewire? confirm), Tab cycles through interactive elements
- [ ] Visual regression dogfood on hihaho under all three modes
- [ ] Tests — accessibility assertions (aria-selected toggles on tab switch)

---

## Open Questions

1. **Scope of "Raw fields" view under Section C.** Peer's sketch says a KV table of all `payload_*` keys; mine strips the metadata-scope keys (they're in Section B). Peer's version is more complete but duplicates the Section B data. Mine avoids the duplication at the cost of a less faithful "raw" label. Lean: strip the metadata keys; comment in the template explaining why.

2. **Humanized duration vs raw ms for short durations.** `CarbonInterval::milliseconds(42)->cascade()->forHumans()` produces `"42 milliseconds"`. Under 1s the humanization is arguably noise. Option A: show raw `42 ms` only when `< 1000ms`, humanize when `>= 1000ms`. Option B: always humanize with the `short => true` flag producing `"42ms" / "1.24s"`. Lean B.

3. **Copy-to-clipboard fallback strategy on non-HTTPS dev environments.** `navigator.clipboard` requires a secure context. Dev via `studio.hihaho.test` (plain `http` on `.test`) may not have access. Options: (a) hide the button entirely outside secure contexts, (b) use the old `document.execCommand('copy')` path with a hidden `<textarea>` — deprecated but still widely supported. Lean (b).

4. **Modal size and overflow behaviour under Section C full-payload.** Current `max-h-[80vh]` + `overflow-auto` handles a long JSON body reasonably. Do we want a "fullscreen" toggle for very long payloads? Defer unless dogfood shows a real problem.

5. **Test strategy for inline JS colorizer.** We can't run JS in feature tests. Options: (a) rely on the raw JSON text being in the DOM + trust the colorizer separately, (b) write a lightweight Dusk/browser test, (c) skip — visual dogfood catches it. Lean (c) for now; revisit if the colorizer becomes load-bearing.

---

<!-- ## Resolved Questions
1. **{Original question?}** **Decision:** {What was decided.} **Rationale:** {Why.}
-->

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
