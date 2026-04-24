# Structured Details Modal

## Overview

Replace the raw `<pre>json_encode(...)</pre>` body in the dashboard's Details modal with a Horizon-style structured layout: grouped sections, humanized values, pills/stat cards, and tier-specific rendering keyed off `capture.payloads`. Backend shape is already sufficient (`$selectedPayload` carries baseFields + stream `_id` + sanitizer output); this is pure template work plus a small inline JSON colorizer.

Drives first-enable UX: every consumer hits the "I flipped capture on, what does it look like?" moment. A structured view makes the difference between "nice" and "usable" and shouldn't require each consumer to fork the published view.

---

## 1. Current State

`resources/views/dashboard.blade.php:186-222` renders the modal. Since `f8384b0`:

- Header carries a capture-mode badge (`capture: off|metadata|full`).
- Body is a single `<pre>` with `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` — flat, monospace, unstructured.
- Footer shows tier-specific escalation copy for `off` and `metadata` modes; no footer on `full`.
- `$selectedPayload` is an `array<string, string>` from `QueueInsights::recentCompleted`. Keys depend on which sanitizer the binding resolves to (see `QueueInsightsServiceProvider:42-53` — `metadata` → `MetadataOnlySanitizer`, `full` → `KeyRedactingSanitizer`; they do NOT compose). Shapes:
  - **Always, every mode**: `_id`, `class`, `connection`, `queue`, `duration_ms`, `attempts`, `processed_at`.
  - **`metadata` mode, normal job** (`MetadataOnlySanitizer`): any of `payload_displayName`, `payload_maxTries`, `payload_timeout`, `payload_backoff` (keys absent from the job payload are absent from the stream — no `—` placeholder at the writer level).
  - **`metadata` or `full` mode, closure/encrypted**: `payload_note='payload_not_persisted'` + `payload_reason='closure_or_encrypted'`. Same shape from both sanitizers.
  - **`full` mode, normal job** (`KeyRedactingSanitizer` happy path): `payload_body` — a JSON-encoded string containing the redacted payload object. Single field, NOT a set of kv pairs.
  - **`full` mode, encoding failure**: `payload_error='payload_encoding_failed'`.
  - **`full` mode, size overflow**: `payload_error='payload_too_large'` + `payload_size='<bytes>'`.

There are **four render shapes** (not three), because `full` and `metadata` disagree on normal-job output. Section B owns the `metadata`-normal shape; Section C owns the `full`-normal shape; both error/closure shapes render as inline status boxes re-used across modes.

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
| Processed at | `processed_at` | ISO string + `Date::parse($v)->diffForHumans()` in gray (use `Date::` facade, not `CarbonImmutable::parse` — respects host app's `Date::use(...)` factory per ff8d40d / `CarbonInterface` widening) |
| Stream ID | `_id` | Monospace, small, with a copy-to-clipboard affordance |

Use a two-column `<dl>` for label/value layout (matches Tailwind CDN idioms already in use).

### Section B: Job config / status (visible when any of `payload_displayName`, `payload_maxTries`, `payload_timeout`, `payload_backoff`, `payload_note`, or `payload_error` is present)

Owns the `metadata`-normal render shape **and** the status branches (closure/encrypted, encoding error, size overflow) that can surface under either `metadata` or `full`. Happy-path job-config content comes from `MetadataOnlySanitizer`; status content comes from the shared failure/bail paths in both sanitizers.

**Job-config branch** (when `payload_displayName` OR any of the three int fields are present):

- `displayName` row — monospace single line, absent if key missing.
- Stats row of up to three labeled cards: `maxTries` / `timeout` / `backoff`. **Cards are absent when their key is absent**, not rendered with a `—` placeholder — writer-side `array_key_exists` already filters, so key presence is truthful. If a card is rendered and its value is empty string, show `—`.
- `backoff` special case: the sanitizer permits array values (list of backoff seconds like `[1, 5, 10]`); the writer JSON-encodes arrays into the stream field via `encodeStreamValue`. Template must `json_decode` the stored value, detect list-shape, and render as a joined list (`"1, 5, 10s"`); fall back to the raw string if decode fails.

**Status branches** (mutually exclusive, render instead of the job-config branch):

- `payload_note === 'payload_not_persisted'`: yellow info box. Body text is driven by `payload_reason` rather than hardcoded, so future sanitizer reasons (`oversized`, etc.) self-document without a spec follow-up. Same branch used by both sanitizers (they return identical closure/encrypted shape).
- `payload_error === 'payload_encoding_failed'`: red info box — "Payload encoding failed". No further detail available (sanitizer doesn't emit one).
- `payload_error === 'payload_too_large'`: red info box — "Payload exceeded size cap ({{ payload_size }} bytes)". Reads `payload_size` when present.

Verified against `src/Support/Sanitizers/MetadataOnlySanitizer.php:19-25` (`['note' => 'payload_not_persisted', 'reason' => 'closure_or_encrypted']`), `KeyRedactingSanitizer.php:28-32` (identical closure/encrypted shape from the same bail path) + `:37-46` (`['body' => …]` happy path, `['error' => 'payload_encoding_failed']`, `['error' => 'payload_too_large', 'size' => N]` overflow), and `RecordJobProcessed.php:133` (writer prefixes every sanitizer key with `payload_`).

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

### Section C: Payload body (visible when `payload_body` key present)

Renders **only** under `capture.payloads=full` happy-path (`KeyRedactingSanitizer` returns `['body' => $encodedJson]` → stream stores as single field `payload_body`). Under `metadata` there is no `payload_body` → section absent.

Under full mode, the stream field `payload_body` is a JSON-encoded **string** (the sanitizer's `json_encode($redacted, ...)` output). Rendering it directly would show escaped JSON-inside-JSON. Template must `json_decode($selectedPayload['payload_body'])` first, then pretty-print the decoded structure. On decode failure (corrupt or unexpected shape), fall back to the raw string inside a monospace `<pre>` with no colorization.

Two-tab toggle inside the section:

- **Sanitized JSON** (default): pretty-printed JSON of the decoded payload body. Syntax-highlighted via a small inline JS colorizer (see §3).
- **Raw fields**: KV table of the **top-level keys of the decoded body**, one row per key. For long string values, truncate to 200 chars with a "…" affordance (click to expand). For nested arrays/objects, render as `json_encode(..., JSON_UNESCAPED_SLASHES)` inline. Both tabs render exactly the same data, different presentations.

Emit a one-line hint at the top of the Raw pane so readers understand the scope: _"Sanitized payload body as key-value table. Job-level metadata (displayName / maxTries / timeout / backoff) shown in Job Config above if present."_ Under `full` mode Section B will typically be absent (KeyRedactingSanitizer doesn't emit displayName etc), which the hint acknowledges via the "if present" qualifier.

Tab state is component-local (`$payloadTab = 'json'` or `'raw'`), defaults to `'json'` on `openPayload`. Livewire public properties persist across poll cycles by default; no special handling needed.

### Footer

Keep the f8384b0 tiered hints exactly as-is for `off` and `metadata`. No footer on `full` (nothing more to escalate to).

## 3. Inline JSON colorizer

Pick **inline ~40-line JS** over a CDN (Prism/highlight.js). Rationale:

- Keeps the package's CDN count at 1 (Tailwind). Adding Prism adds a second third-party fetch per dashboard load.
- Works offline / in air-gapped environments (some consumer Vapor deployments proxy outbound traffic).
- The colorizer only needs to handle pretty-printed JSON output — no multi-language grammar needed.
- Zero new failure modes from third-party JS semver drift.

**Script placement — one safe path only.** Inline in `layouts/app.blade.php` `<head>`, emitted BEFORE `@livewireScripts` so the listener registers before Livewire boots. Do NOT emit from the modal wrapper or anywhere inside the component render — by the time the modal's DOM exists, `livewire:initialized` has already fired and a one-shot listener added there would never run.

The bootstrap uses a two-branch guard so it works regardless of whether the script runs before or after Livewire has initialized:

```js
function registerHook() {
    Livewire.hook('morph.updated', ({el}) => { /* colorize, see below */ });
}
if (window.Livewire) {
    registerHook();
} else {
    document.addEventListener('livewire:initialized', registerHook, { once: true });
}
```

This belt-and-suspenders form tolerates future refactors that move `@livewireScripts` above the inline block, and keeps the Phase-2 task explicit about placement to prevent the silent-no-op failure mode.

Implementation sketch (the colorizer itself, placed inside `registerHook`):

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

The hook body (`registerHook` from the bootstrap above) re-runs after every DOM morph — 10s poll ticks, tab toggles, modal open/close. Target `[data-json-highlight]` nodes; source is their textContent, output writes the colorized HTML back into the node.

```js
function registerHook() {
    Livewire.hook('morph.updated', ({el}) => {
        el.querySelectorAll('[data-json-highlight]').forEach(node => {
            // Idempotency guard — the hook fires on every 10s poll morph, not just modal
            // open. Re-colorizing unchanged content would flicker and reset scroll.
            // Compare against the previous source directly (stored on an expando property,
            // not a data-attribute, to avoid a 16KB string ending up in serialized DOM
            // snapshots). Full-string equality — length-based fingerprints would collide
            // on different payloads of the same length starting with `{` or `[`, causing
            // a newly selected payload to render unhighlighted.
            const src = node.textContent;
            if (node._qiColorizedSrc === src) return;

            const escaped = highlightJson(src);
            node.replaceChildren();
            node.insertAdjacentHTML('afterbegin', escaped);
            node._qiColorizedSrc = src;
        });
    });
}
```

The guard uses full-string equality against the previous source stored on an expando property (`node._qiColorizedSrc`) rather than a fingerprint. Length-based fingerprints would collide on different JSON bodies of the same length starting with the same character (`{` / `[` are near-universal openings), silently skipping re-colorization for a legitimately-changed payload. Full-string memory cost is bounded by the 16KB payload cap — negligible per modal, and the expando property stays out of DOM attribute dumps / serialization.

**Invariant — do not reorder.** `highlightJson()` HTML-escapes `& < >` as its *first* step before wrapping token spans. This is the **sole CLIENT-SIDE XSS defense** on the colorizer path — Blade's `{{ }}` already escapes at server-render time, but the client-side `node.textContent` read then `insertAdjacentHTML` write round-trip bypasses that first layer. Both defenses are needed; removing either opens the hole.

Two-layer threat model:

1. **Server → DOM** — `{{ json_encode($body, JSON_PRETTY_PRINT) }}` emits JSON text. PHP's `json_encode()` does NOT escape `<` / `>` (no `JSON_HEX_TAG`), so a job payload containing `{"foo": "<script>alert(1)</script>"}` produces a JSON string with literal `<script>` characters. Blade's `{{ }}` then HTML-entity-escapes the output, so the HTML attribute stream reads `&lt;script&gt;`. If the JSON pane were rendered as-is and never touched again, `<pre>` would display the entity-escaped content literally. Safe layer one.

2. **DOM → colorizer → DOM** — the colorizer reads `node.textContent`, which **decodes entities** back into raw `<` and `>`. Without `highlightJson()`'s escape-first step, the next call `node.insertAdjacentHTML('afterbegin', …)` re-parses the injected string as markup — the `<script>` tag is re-materialized from the un-escaped text and executes. Escape-first re-converts `< >` to entities before any `insertAdjacentHTML` call, so the injected markup contains only `&lt;script&gt;` entity tokens, which the browser renders as literal characters inside a `<span>`. No re-parse as script.

Swapping the client-side order also breaks the colorizer cosmetically (span tags would be re-escaped into visible `&lt;span&gt;` text), but the security guarantee is the load-bearing reason for the ordering — the cosmetic break just happens to be a loud signal that something went wrong.

**Regression guard** (belongs in Phase 3 tests). The hostile input must be a VALID JSON body, because Section C's `json_decode` with raw-string fallback routes invalid JSON to the non-colorized `<pre>` fallback — which is not the code path at risk. A fixture like `payload_body = '<script>alert(1)</script>'` (raw, not JSON) exercises the decode-failure path and proves nothing about the colorizer. The correct fixture wraps the hostile content inside a valid JSON structure so it reaches the colorizer.

Use `payload_body = '{"foo": "<script>alert(1)</script>"}'` — valid JSON → `json_decode` succeeds → routed to the colorizer pane → Blade's `{{ }}` renders it with `&lt;script&gt;` entities in the HTML attribute. The PHPUnit assertion checks the server-rendered HTML contains `&lt;script&gt;` and does NOT contain a literal `<script>` tag inside the `[data-json-highlight]` pane. This proves layer 1 (Blade server-side escape) runs on hostile input that will actually reach the colorizer. Layer 2 (client-side escape-first) is not directly tested — caught by visual dogfood and the do-not-reorder invariant.

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
- `$sectionBKeys = ['payload_displayName', 'payload_maxTries', 'payload_timeout', 'payload_backoff', 'payload_note', 'payload_reason', 'payload_error', 'payload_size']`
- `$sectionCBodyRaw = $selectedPayload['payload_body'] ?? null`
- `$sectionCBody = is_string($sectionCBodyRaw) ? (json_decode($sectionCBodyRaw, true) ?? $sectionCBodyRaw) : null` — decoded structure, or raw string on decode failure, or `null` when key absent

Note: `payload_note`, `payload_reason`, `payload_error`, `payload_size` belong to Section B (status branches) not the Raw tab in Section C. Section C only shows the decoded `payload_body` contents.

## 5. Accessibility + interaction

Dialog semantics on the modal wrapper:

- `role="dialog"` + `aria-modal="true"` on the backdrop container.
- `aria-labelledby` pointing at the "Details" `<h3>` id.
- Focus trap via Alpine's `x-trap` directive (Alpine is bundled with Livewire 3 along with the Focus plugin — verified by grep of `vendor/livewire/livewire/dist/livewire.esm.js` for `FocusTrap`; zero new dep, one-attribute change). Moves focus into the modal on open, returns it to the trigger `<button>` on close. Without a trap, keyboard users Tab straight out into the background dashboard.
- `inert` on **a sibling container**, not on `<main>` or the component root. The modal is rendered inside the same Livewire component wrapper as the dashboard content (see `resources/views/dashboard.blade.php` and `layouts/app.blade.php` — `<main>` contains `{{ $slot }}` which is the component's render, which contains both `<div wire:poll.10s>...dashboard content...</div>` and the modal backdrop). Applying `inert` to `<main>` or the top-level component wrapper makes the modal itself inert and breaks the focus trap. Correct shape — split the component template so the dashboard content and the modal are siblings under the component root, then toggle `inert` on the content sibling only:
  ```blade
  <div wire:poll.10s class="space-y-8">
      <div x-bind:inert="$wire.selectedPayloadId !== null" id="qi-dashboard-content">
          {{-- Queue cards, job classes, recent completed, recent failed all move inside here --}}
      </div>

      {{-- Modal stays outside the inerted sibling, so it remains interactive --}}
      @if ($selectedPayload !== null)
          <div role="dialog" aria-modal="true" ...>
              ...
          </div>
      @endif
  </div>
  ```
  Blocks AT users from hearing the background content mid-modal without accidentally inerting the dialog itself.
- Modal wrapper retains `wire:click="closePayload"` on the backdrop + `wire:click.stop` on the content (unchanged from f8384b0).

Per-element:

- Copy-to-clipboard on Stream ID: `<button>` with `aria-label="Copy stream id"`; primary path `navigator.clipboard.writeText`; fallback Selection-API path per Resolved Q #3 (do NOT use `document.execCommand('copy')`).
- Tab toggle: semantic `<button>` elements with `role="tab"` + `aria-selected="true|false"` + `aria-controls` pointing at the panel id; panels `role="tabpanel"`.
- Dates: both raw ISO and humanized rendered, no information hiding for copy-paste / machine parsing.

Out of scope for this spec: ARIA live regions for the 10s poll-driven updates. That is a dashboard-wide concern (queue cards, job-classes table, failed-jobs table all change on poll too), not a modal-specific one; handle in a separate a11y pass.

## 6. Out of scope (explicit)

- Clickable Class → `selectClass`. Row in the table above is already clickable; doubling the interaction doubles the keyboard/accessibility semantics work for zero new capability. Revisit if there's a real use case (e.g. "filter by class" from inside the modal without closing it).
- Job timeline / retry history. Would need new backend reads (replay the per-class stream, cross-reference failed_jobs). Separate spec.
- Payload diffing between attempts. Same reason.
- Syntax highlighting theme toggle. Single theme (Tailwind default palette) matches the rest of the dashboard.

## 7. Testing

Feature tests (Livewire, `tests/Feature/Http/DashboardComponentTest.php`). The four render shapes drive the test matrix:

- **Base metadata always visible** (off / metadata-normal / metadata-closure / full-normal / full-closure / full-encoding-error / full-size-overflow) — assert class/connection/queue/duration/attempts/processed-at/stream-id all present in each.
- **Section B absent under off** — no `payload_*` keys at all, so no job-config row and no status box.
- **Section B job-config cards under metadata-normal** — seed stream with `payload_displayName` + `payload_maxTries` + `payload_timeout` + `payload_backoff`, assert each card present.
- **Section B partial cards when keys missing** — seed with only `payload_displayName` + `payload_maxTries`, assert timeout + backoff cards ABSENT (not rendered with `—`).
- **Section B backoff array decode** — seed `payload_backoff = '[1,5,10]'`, assert rendered output contains `"1, 5, 10"`, not the raw JSON string.
- **Section B closure/encrypted yellow box** — seed `payload_note` + `payload_reason`, assert yellow box with reason text visible, stats cards absent. Covered for both metadata and full modes.
- **Section B encoding-error red box** — seed `payload_error='payload_encoding_failed'`, full mode only, assert red box + no stats.
- **Section B size-overflow red box** — seed `payload_error='payload_too_large'` + `payload_size='20480'`, assert red box reads "Payload exceeded size cap (20480 bytes)".
- **Section C absent under off and metadata** — no `payload_body` key.
- **Section C present under full-normal** — seed `payload_body='{"foo":"bar"}'`, assert JSON tab visible, default tab is JSON, `setPayloadTab('raw')` flips to raw KV table containing `foo` / `bar`.
- **Section C decode-failure fallback** — seed `payload_body='not valid json'`, assert raw string renders inside `<pre>` without colorizer tokens (no `<span class="text-green-700">`).
- `setPayloadTab` with invalid input is a no-op (consistent with `setHistoryMetric`).
- `openPayload` resets `$payloadTab` to `'json'` even if a prior open left it on `'raw'`.
- **DOM-contract**: rendered JSON pane carries `[data-json-highlight]` attribute (Phase 3).
- **XSS regression (server-side / layer 1)**: seed `payload_body='{"foo": "<script>alert(1)</script>"}'` (valid JSON containing hostile string value — reaches the colorizer pane, not the decode-failure fallback). Assert rendered HTML inside the `[data-json-highlight]` node contains `&lt;script&gt;` and does NOT contain a literal `<script>` tag (Phase 3).
- **Colorizer sink execution (client-side / layer 2, CI-mandatory)**: PHPUnit test extracts BOTH the `highlightJson` function source AND the hook body (`el.querySelectorAll('[data-json-highlight]').forEach(...)` block) from `resources/views/layouts/app.blade.php`, shells out to `node -e` with a minimal DOM shim (~30 LOC — `querySelectorAll` returning a single node, `textContent` get/set, `replaceChildren`, `insertAdjacentHTML('afterbegin', ...)` appending to an `_html` buffer, `dataset`). Seeds the shim node's `textContent` with hostile input `{"foo": "<script>alert(1)</script>"}`, runs the extracted hook body against the shim `el`, asserts the final `_html` buffer contains `&lt;script&gt;` and does NOT contain a literal `<script>` tag. This tests the **full sink path** (`highlightJson` + `insertAdjacentHTML` + idempotency guard), not just the transformer in isolation — so a future refactor that bypasses `highlightJson` or writes unsafe HTML at the sink is caught.

  **Node is pinned in CI** via an `actions/setup-node@v4` step added to `.github/workflows/run-tests.yml` (one step, cached). Test checks `getenv('CI') === 'true'` — in CI, missing Node is a hard fail; locally, missing Node falls back to `markTestSkipped` so dev convenience is preserved without relaxing the CI gate. The two-mode behaviour guarantees every push to `main` has the sink-path defense verified while not demanding a JS runtime for casual package-dev.

  Paired with a source-order sanity assertion (escape regex appears before token-wrapping regex) as a cheap fail-fast when extraction can't locate the hook body (Phase 3).

Visual regression (manual for now): open each tier in a browser via the dogfood symlink on hihaho. Specifically verify status-branch renders (closure job, oversized payload) — these are hard to exercise locally without a real job failure path.

## Implementation

### Phase 1: Component + view restructure (Priority: HIGH)

- [ ] Add `public string $payloadTab = 'json'` to `QueueInsightsDashboard`
- [ ] Add `setPayloadTab(string $tab): void` with `['json', 'raw']` allowlist (silently drops invalid input, same no-op pattern as `setHistoryMetric`)
- [ ] Reset `$payloadTab = 'json'` in `openPayload()` on each open
- [ ] Pass `$payloadTab` to the view in `render()`
- [ ] Split `dashboard.blade.php` modal body into Section A / B / C per shape above
- [ ] Add `$baseKeys` / `$sectionBKeys` / `$sectionCBody` derivations at the top of the modal block
- [ ] Tests — component properties, tab allowlist/reset semantics, `openPayload` reset behavior

### Phase 2: Presentation (Priority: HIGH)

- [ ] Section A: two-column `<dl>` with connection/queue pills, humanized duration via `CarbonInterval::milliseconds(...)->cascade()->forHumans(['short' => true])` + raw ms in gray, `Date::parse($v)->diffForHumans()` timestamp, stream-id `<code>` + copy button
- [ ] Section B job-config branch: conditional rows — `displayName` only when present, stats cards only for the keys that exist (no `—` placeholder cards), `payload_backoff` array-decode rendering
- [ ] Section B status branches: yellow `payload_not_persisted` box driven by `payload_reason`; red `payload_encoding_failed` box; red `payload_too_large` box with `payload_size` readout
- [ ] Section C: `payload_body` present guard, `json_decode` with raw-string fallback, tab header (`role="tab"` + `aria-selected` + `aria-controls` / `role="tabpanel"`) + JSON pane (with `[data-json-highlight]` attribute) + raw KV pane (truncation at 200 chars with expand), scope hint line
- [ ] Inline JS JSON colorizer in `layouts/app.blade.php` `<head>` (~40 LOC, no external dep), placed before `@livewireScripts`, with the full-string idempotency guard via `node._qiColorizedSrc` expando property (NOT a `data-colorized` attribute or fingerprint hash — see Resolved Q #15)
- [ ] Copy-to-clipboard JS snippet with `navigator.clipboard` + Selection-API fallback
- [ ] Tests — every render shape in §7 test matrix (off / metadata-normal / metadata-partial / metadata-closure / full-normal / full-closure / full-encoding-error / full-size-overflow / decode-failure)

### Phase 3: Polish + accessibility (Priority: MEDIUM)

- [ ] Add `role="dialog"` + `aria-modal="true"` + `aria-labelledby` on the modal backdrop
- [ ] Wrap content in Alpine `x-trap` — focus moves in on open, returns to trigger on close
- [ ] Restructure `dashboard.blade.php` to nest the dashboard content (queue cards, job classes, completed, failed) inside a sibling `<div id="qi-dashboard-content" x-bind:inert="$wire.selectedPayloadId !== null">` under the component root. Modal stays as its own sibling under the root, NOT inside the inerted wrapper. Never apply `inert` to `<main>`, the component root, or any ancestor of the modal — doing so inerts the dialog itself and breaks the focus trap + pointer interaction. See §5 for the template shape
- [ ] `aria-label`s on copy button + tab toggles; `role="tab"` + `aria-selected` + `aria-controls` / `role="tabpanel"` on the C-section toggle
- [ ] Keyboard handling: Esc closes modal, Tab cycles within the trap
- [ ] DOM-contract assertion: rendered JSON pane carries the `[data-json-highlight]` attribute — pins the JS-to-DOM contract so a template refactor can't silently break the colorizer
- [ ] XSS regression assertion (layer 1 — server-side Blade escape): seed a stream row with `payload_body = '{"foo": "<script>alert(1)</script>"}'` (valid JSON reaching the colorizer, not the decode-failure fallback), render the component, assert the rendered HTML inside the `[data-json-highlight]` node contains `&lt;script&gt;` and does NOT contain a literal `<script>` tag. See §3's "Invariant — do not reorder" block
- [ ] Colorizer sink-execution assertion (layer 2 — full client-side path, CI-mandatory): PHPUnit extracts BOTH the `highlightJson` function source AND the hook body from `layouts/app.blade.php`; shells out to `node -e` with a ~30 LOC minimal DOM shim (`{_html, _text, get/set textContent, replaceChildren, insertAdjacentHTML('afterbegin', ...), dataset, querySelectorAll}`); seeds `textContent = '{"foo": "<script>alert(1)</script>"}'`, runs the hook body against the shim, asserts the final `_html` buffer contains `&lt;script&gt;` and NOT literal `<script>`. Tests the full sink (`highlightJson` + `insertAdjacentHTML` + idempotency guard), so refactors that bypass `highlightJson` or change the sink are caught. Two-mode probe: `$nodeMissing = (int) shell_exec('node --version > /dev/null 2>&1; echo $?') !== 0; if ($nodeMissing && getenv('CI') === 'true') $this->fail('Node required in CI for layer-2 XSS test'); elseif ($nodeMissing) $this->markTestSkipped('node not available locally')`. Pair with a source-order sanity assertion as a fail-fast when the extraction regex can't locate the hook body
- [ ] Add `actions/setup-node@v4` step to `.github/workflows/run-tests.yml` (before the `Execute tests` step), pinning Node to a stable major (e.g. 20.x). Ensures the layer-2 sink test always runs in CI — without this, ubuntu-latest's default Node could be swapped out by a future runner image change, silently reintroducing the coverage gap
- [ ] Visual regression dogfood on hihaho under all three modes
- [ ] Tests — a11y attributes (aria-modal, aria-selected toggles) + DOM-contract + XSS regression (both layers) assertions

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

10. **XSS regression guard (peer review of §3 XSS invariant, fixture corrected during self-eval).** **Decision:** Add a paired PHPUnit assertion in Phase 3 using the fixture `payload_body = '{"foo": "<script>alert(1)</script>"}'` — VALID JSON carrying hostile content so the decode succeeds and the colorizer pane is actually reached. Assert the rendered HTML inside `[data-json-highlight]` contains `&lt;script&gt;` and does NOT contain literal `<script>`. **Rationale:** the initial peer-suggested fixture was `payload_body = '<script>alert(1)</script>'` (raw, not JSON), which routes through Section C's decode-failure fallback into a non-colorized `<pre>`. That fallback is not the risky code path — it proves nothing about the escape-first invariant. The corrected valid-JSON fixture reaches the colorizer pane where Blade's `{{ }}` server-side escape is load-bearing, which is what the regression test should pin. The DOM-contract assertion (also Phase 3) pins that the `[data-json-highlight]` attribute is present; together they cover both halves of the two-layer threat model without requiring a JS runtime. Layer 2 (client-side `highlightJson()` escape-first) is not directly tested — caught by visual dogfood and the do-not-reorder invariant in §3.

11. **Sanitizer-shape survey (self-review of spec before codex-review).** **Decision:** Rewrote §1, Section B, Section C, §4, and §7 to reflect the actual shapes emitted by `MetadataOnlySanitizer` and `KeyRedactingSanitizer` — they do not compose, and `full`-mode writes a single `payload_body` JSON string rather than a set of `payload_*` kv pairs. Section B now owns three status branches (closure/encrypted, encoding error, size overflow) that surface across both modes; Section C owns `payload_body` specifically with json_decode + raw-string fallback. `$metadataKeys` replaced with `$sectionBKeys` including `payload_error` + `payload_size` + `payload_reason`. Test matrix expanded to nine shapes (off / metadata-{normal,partial,closure} / full-{normal,closure,encoding-error,size-overflow,decode-failure}). **Rationale:** the prior wording would have yielded an empty Section B under `full`-normal (sanitizer emits no `displayName` under `full`), a broken Section C pretty-print showing `{"payload_body":"{…}"}` as doubly-wrapped JSON, and zero coverage of the three failure branches the sanitizer already emits. Implementer would have discovered this at Phase 2 and had to re-spec.

12. **Colorizer poll-tick idempotency (self-review, superseded by #15).** **Decision:** `Livewire.hook('morph.updated', …)` fires on every 10s poll — re-running `highlightJson()` + `insertAdjacentHTML('afterbegin', …)` each tick would reset scroll position inside open modals and flicker the JSON pane. Initial proposal was a `data-colorized="<length:firstCharCode>"` fingerprint guard. **Superseded by Resolved Q #15** — the fingerprint had a collision hazard; final design is full-string equality on `node._qiColorizedSrc`. Keeping this entry for history so future readers see the reasoning chain.

13. **`inert` target — sibling container, not `<main>` (codex review finding).** **Decision:** Wrap the dashboard content inside a sibling `<div>` under the component root and toggle `inert` on the sibling only. Do NOT apply `inert` to `<main>` or the top-level `<div wire:poll.10s>`. **Rationale:** the modal is rendered inside the same Livewire component template as the dashboard content, which is inside `{{ $slot }}`, which is inside `<main>`. Making any ancestor of the modal inert propagates to the modal itself and breaks the focus trap + pointer interaction. Template restructure: `<div wire:poll.10s>` contains `<div id="qi-dashboard-content" x-bind:inert="...">...cards/tables...</div>` + the modal as siblings.

14. **Colorizer bootstrap placement and dual-branch registration (codex review finding).** **Decision:** Inline `<script>` block emitted in `layouts/app.blade.php` `<head>`, placed BEFORE `@livewireScripts`. Do NOT emit from the modal wrapper or from inside the component render. Bootstrap uses a dual-branch guard: if `window.Livewire` already exists, register the hook immediately; otherwise add a one-shot `livewire:initialized` listener. **Rationale:** the prior "placed inline in `layouts/app.blade.php` or emitted inside the modal wrapper" wording had two silent-failure modes. (a) Script injected from the modal wrapper arrives in the DOM after `livewire:initialized` has fired, so a one-shot listener never executes — colorizer is permanently un-registered, JSON pane renders plain text, no visible error. (b) A future refactor moving `@livewireScripts` above the inline script in the layout produces the same outcome. The dual-branch form tolerates both and makes the placement requirement explicit.

15. **Idempotency guard — full-string equality, not fingerprint (codex review finding).** **Decision:** Store the previous source on an expando property `node._qiColorizedSrc` and compare with full-string equality. Do NOT use a `length:firstCharCode` or similar cheap fingerprint. **Rationale:** JSON bodies typically start with `{` or `[`, and different payloads of the same length are not rare — a length-based fingerprint would skip re-colorization for legitimately changed content and leave the newly selected payload rendered as plain text. Memory cost of storing the source is bounded by the 16KB payload cap, negligible per modal. Expando property (not `dataset.colorizedSrc`) keeps the string out of serialized DOM attribute dumps.

16. **Phase 3 `inert` checklist — sibling wrapper, not `<main>` (codex review follow-up).** **Decision:** The Phase 3 task list now mirrors §5: move dashboard content into a sibling `<div id="qi-dashboard-content">` under the component root, apply `inert` only to that sibling, never to `<main>` or any ancestor of the modal. **Rationale:** the initial Phase 3 checklist item ("Toggle inert on the main dashboard container") contradicted §5's sibling-container fix. An implementer following the checklist would have reintroduced the inert-the-dialog bug. Task list now carries the template shape explicitly.

17. **Client-side colorizer test strategy (codex review finding, iterated across passes 3–4).** **Decision:** PHPUnit test extracts BOTH `highlightJson` and the hook body from `layouts/app.blade.php`, shells out to `node -e` with a ~30-line hand-rolled DOM shim, runs the hook body end-to-end against a shim node seeded with hostile `textContent`, and asserts the final `_html` state. Tests the full sink (`highlightJson` + `insertAdjacentHTML` + idempotency guard), not the transformer in isolation. Companion source-order sanity assertion as a fail-fast for extraction failures. Skips gracefully when Node is unavailable. Skip full browser testing (Dusk) and avoid bundling a JS test runner (Vitest, jsdom via npm) as composer/npm dev deps. **Rationale evolution:**
   - Pass 3 initial design was source-order-only; codex flagged that a refactor could pass source-order while changing the sink. True.
   - Pass 4 first attempt was `node -e` on `highlightJson` in isolation; codex flagged that that still doesn't cover the `insertAdjacentHTML` sink — a refactor could change the hook to skip `highlightJson` entirely. True.
   - Final design runs the full hook body against a hand-rolled DOM shim (~30 LOC in the test JS stdin). Minimal shim covers only the sink methods used (`querySelectorAll`, `textContent`, `replaceChildren`, `insertAdjacentHTML`, `dataset`) — not a full DOM, but sufficient to prove the sink safety for the specific methods used in the hook. A full `jsdom` install would be cleaner but adds npm infra; CI-only shell-out with a minimal shim avoids the infra bill.
   - **Known gap:** shim doesn't reproduce real browser HTML parsing (no actual `<script>` execution semantics). The assertion is string-based (`contains &lt;script&gt;` + `does not contain literal <script>`), which is the proxy for "browser would render as text, not markup". Acceptable — if `insertAdjacentHTML` receives entity-escaped input it always renders as text per HTML spec. If a future refactor wants stronger guarantees, upgrade to jsdom or Dusk.

   - **CI-pinning (pass 5):** `markTestSkipped` when Node is absent would let CI go green without running the test. Unacceptable for an XSS defense. Added a two-mode probe: in CI (`getenv('CI') === 'true'`) a missing Node `$this->fail`s the test; locally, it still skips for dev convenience. Paired with an explicit `actions/setup-node@v4` step in `.github/workflows/run-tests.yml` pinning Node 20.x, so future ubuntu-latest image changes can't silently drop the runtime.



## Findings

<!-- Notes added during implementation. Do not remove this section. -->
