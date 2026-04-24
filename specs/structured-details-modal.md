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
- If `payload_note === 'payload_not_persisted'`: **replace** the stats row with a yellow info box. Body text is driven by the sanitizer's `payload_reason` field rather than hardcoded in the template, so future sanitizer reasons (`oversized`, etc.) self-document without a spec follow-up.

Verified against `src/Support/Sanitizers/MetadataOnlySanitizer.php:19-25` (sanitizer returns `['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted']`) and `RecordJobProcessed.php:133` (writer prefixes every sanitizer key with `payload_`, so the stream carries `payload_note` + `payload_reason`).

```blade
@if (($selectedPayload['payload_note'] ?? null) === 'payload_not_persisted')
    <div class="rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
        Payload not persisted
        @if ($reason = $selectedPayload['payload_reason'] ?? null)
            — {{ str_replace('_', ' ', $reason) }}
        @endif
    </div>
@endif
```

### Section C: Payload (visible when any `payload_*` key beyond the metadata set exists)

Renders under `capture.payloads=full`. Two-tab toggle inside the section:

- **Sanitized JSON** (default): pretty-printed JSON of the `payload_*` keys (minus the metadata ones which live in Section B). Syntax-highlighted via a small inline JS colorizer (see section 3).
- **Raw fields**: KV table of the same data, one row per `payload_*` key. For long values, truncate to 200 chars with a "…" affordance (click to expand).

Both tabs scope to the **payload bucket only** — job-config fields (`displayName` / `maxTries` / `timeout` / `backoff`) live in Section B and are intentionally omitted here to avoid duplication. Emit a one-line hint at the top of the Raw pane so the label stays honest: _"Job-config fields shown in Job Config above."_

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

Wire up via the Livewire 3 hook API. `livewire:initialized` bootstraps the listener; `Livewire.hook('morph.updated', ...)` re-runs it after every DOM morph (including 10s poll ticks, tab toggles, and modal open/close). Target `[data-json-highlight]` nodes; source is their textContent, output writes the colorized HTML back into the node.

```js
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('morph.updated', ({el}) => {
        el.querySelectorAll('[data-json-highlight]').forEach(node => {
            const escaped = highlightJson(node.textContent);
            node.replaceChildren();
            node.insertAdjacentHTML('afterbegin', escaped);
        });
    });
});
```

**Invariant — do not reorder.** `highlightJson()` HTML-escapes `& < >` as its *first* step before wrapping token spans. Payload bodies carrying `<script>` text then render as literal escaped characters, never as executable markup. Swapping the order (wrap first, escape later) would re-escape the span tags and break the colorizer; more importantly, escaping after token-wrapping would break the XSS guarantee if the regex ever mis-classifies a `<` inside a string.

An Alpine.js alternative (`x-data` + `x-init` + `$watch('$wire.selectedPayloadId')`) is viable since Alpine is bundled with Livewire 3 — leaving as a Finding note if the `Livewire.hook` path ever becomes awkward. Default path is the hook because it's dispatch-once and covers all modal re-renders without per-instance Alpine state.

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

Dialog semantics on the modal wrapper:

- `role="dialog"` + `aria-modal="true"` on the backdrop container.
- `aria-labelledby` pointing at the "Details" `<h3>` id.
- Focus trap via Alpine's `x-trap` directive (Alpine is bundled with Livewire 3, zero new dep, one-attribute change). Moves focus into the modal on open, returns it to the trigger `<button>` on close. Without a trap, keyboard users Tab straight out into the background dashboard.
- `inert` on the main dashboard container while the modal is open — `x-bind:inert="$wire.selectedPayloadId !== null"` on `<main>`. Blocks AT users from hearing the background content mid-modal.
- Modal wrapper retains `wire:click="closePayload"` on the backdrop + `wire:click.stop` on the content (unchanged from f8384b0).

Per-element:

- Copy-to-clipboard on Stream ID: `<button>` with `aria-label="Copy stream id"`; primary path `navigator.clipboard.writeText`; fallback Selection-API path per §6 (do NOT use `document.execCommand('copy')`).
- Tab toggle: semantic `<button>` elements with `role="tab"` + `aria-selected="true|false"` + `aria-controls` pointing at the panel id; panels `role="tabpanel"`.
- Dates: both raw ISO and humanized rendered, no information hiding for copy-paste / machine parsing.

Out of scope for this spec: ARIA live regions for the 10s poll-driven updates. That is a dashboard-wide concern (queue cards, job-classes table, failed-jobs table all change on poll too), not a modal-specific one; handle in a separate a11y pass.

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

- [ ] Add `role="dialog"` + `aria-modal="true"` + `aria-labelledby` on the modal backdrop
- [ ] Wrap content in Alpine `x-trap` — focus moves in on open, returns to trigger on close
- [ ] Toggle `inert` on the main dashboard container while modal is open
- [ ] `aria-label`s on copy button + tab toggles; `role="tab"` + `aria-selected` + `aria-controls` / `role="tabpanel"` on the C-section toggle
- [ ] Keyboard handling: Esc closes modal, Tab cycles within the trap
- [ ] DOM-contract assertion: rendered JSON pane carries the `[data-json-highlight]` attribute — pins the JS-to-DOM contract so a template refactor can't silently break the colorizer
- [ ] Visual regression dogfood on hihaho under all three modes
- [ ] Tests — a11y attributes (aria-modal, aria-selected toggles) + DOM-contract assertion

---

## Open Questions

None. All questions resolved (see Resolved Questions below).

Two items flagged for deferred revisit rather than open decisions:

- **Modal size / fullscreen toggle under long payloads.** `max-h-[80vh]` + `overflow-auto` handles the 16KB byte-cap comfortably. Revisit only if a real consumer hits wrapping or readability issues. Not blocking implementation.
- **Inline colorizer browser testing.** Strategy is (c) — skip Dusk, rely on visual dogfood. Already covered by the DOM-contract PHPUnit assertion in Phase 3 (pins `[data-json-highlight]` presence). Revisit if the colorizer becomes load-bearing (e.g. if a consumer reports specific JSON shapes rendering incorrectly).

---

## Resolved Questions

1. **Scope of "Raw fields" view under Section C (Open Q #1).** **Decision:** Strip metadata-scope keys from the Raw tab; show only the non-metadata `payload_*` keys. **Rationale:** Duplication with Section B confuses more than a less-faithful "raw" label hurts. Mitigate by emitting a one-line hint at the top of the Raw pane: _"Job-config fields shown in Job Config above."_ The "raw" label remains honest scoped to the payload bucket (what the `PayloadSanitizer` returned beyond the always-on metadata set).

2. **Humanized duration vs raw ms (Open Q #2).** **Decision:** Option B — always humanize with `short => true` (`"42ms"`, `"1.24s"`). Keep raw `"(1243 ms)"` in gray alongside. **Rationale:** Consistent formatting across all durations; `"42ms"` is no noisier than `"42 ms"` raw; user scanning for "slow jobs" benefits from a uniform presentation. Short-mode humanization also handles sub-ms / minute / hour ranges gracefully without per-threshold branching.

3. **Copy-to-clipboard fallback on non-HTTPS dev (Open Q #3, also channel Q #2).** **Decision:** Option (b) — try `navigator.clipboard.writeText` first, fall back to selecting the stream-id `<code>` text via `Selection` API so the user can hit Cmd/Ctrl-C themselves. Do NOT use `document.execCommand('copy')` — deprecated, Chrome/Safari have signaled removal. **Rationale:** hihaho's local dogfood is on `studio.hihaho.test` (plain `http` on `.test`), `navigator.clipboard` is blocked outside secure contexts. The select-and-prompt fallback is forward-compatible (no deprecated API), works in every browser, and surfaces a visible affordance ("Press Cmd+C") better than silent failure. Implementation:
   ```js
   async function copyOrSelect(text, node) {
       try { await navigator.clipboard.writeText(text); return 'copied'; }
       catch { /* fall through */ }
       const range = document.createRange();
       range.selectNode(node);
       getSelection().removeAllRanges();
       getSelection().addRange(range);
       return 'selected';
   }
   ```
   Toast / inline message swaps based on the return value: "Copied" vs "Select → Cmd+C".

4. **Section B yellow-box trigger key (channel Q #4).** **Decision:** `payload_note === 'payload_not_persisted'` is correct (verified against `MetadataOnlySanitizer.php:19-25`). Drive the body text from `payload_reason` instead of hardcoded copy so future reasons self-document; see Section B for the Blade shape.

5. **Clickable Class → `selectClass` from modal (channel Q #3).** **Decision:** Out of scope — the row in the table above is already clickable, doubling the interaction doubles a11y semantics for zero new capability. **Rationale:** if real use cases surface (e.g. "filter from inside the modal without closing"), revisit as a follow-up.

6. **Phase 3 a11y scope (channel Q #5).** **Decision:** Esc + Tab is insufficient; upgrade to `role="dialog"` + `aria-modal="true"` + `aria-labelledby` + Alpine `x-trap` focus trap + `inert` on background while modal is open. Skip ARIA live regions for poll-driven updates — dashboard-wide concern, separate spec. Details in §5.

7. **Inline colorizer Livewire 3 wiring (raised during peer review of §3).** **Decision:** Use `Livewire.hook('morph.updated', ...)` inside a `livewire:initialized` listener. **Rationale:** `livewire:update` is not a Livewire 3 event; `morph.updated` is the v3 lifecycle hook that fires after every DOM morph (polls, tab toggles, modal open/close). Alpine-per-instance (`x-data` + `$watch`) is a viable alternative but the hook path is dispatch-once and covers all re-renders without per-instance state. Code in §3.

8. **Colorizer XSS invariant (raised during peer review of §3).** **Decision:** `highlightJson()` must HTML-escape as the first step, then wrap tokens. **Rationale:** payload bodies can carry `<script>` text; escape-before-wrap renders them as literal characters. Wrap-before-escape would re-escape span tags and break the colorizer, and any regex mis-classification would create an XSS hole. Ordering is load-bearing — documented as an invariant in §3.

9. **Copy-to-clipboard fallback (Open Q #3 follow-up — `document.execCommand` elimination).** **Decision:** Declined `document.execCommand('copy')` (deprecated, Chrome/Safari signaled removal). Selection-API fallback with a visible "Press Cmd+C" prompt replaces it. Code in §3's copy section and Resolved #3 above.



## Findings

<!-- Notes added during implementation. Do not remove this section. -->
