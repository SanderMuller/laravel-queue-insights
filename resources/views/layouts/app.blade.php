@php($qiThemeEnabled = \SanderMuller\QueueInsights\Support\Config::bool('dashboard.theme.enabled', false))
@php($qiClockEnabled = \SanderMuller\QueueInsights\Support\Config::bool('dashboard.clock.enabled', true))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if($qiThemeEnabled)
        {{-- Tells the browser the page is theme-aware so native form controls,
             scrollbars, and the default canvas paint correctly in both modes.
             Gated on the master flag — when off, the dashboard is always-light
             and we don't claim dark-mode support. --}}
        <meta name="color-scheme" content="light dark">
    @endif
    <title>Queue Insights</title>
    {{-- Inter font-family is *declared* up front so the system-ui fallback
         renders immediately and downstream `font-feature-settings` apply
         without waiting on the remote CSS. The actual font file fetch is
         non-blocking and happens lower in <head> — see the preload-swap
         block after the theme-init script. That ordering matters: a
         render-blocking <link rel="stylesheet"> would stall the
         theme-init script and break the no-FOIT contract documented in
         .ai/docs/dashboard-dark-mode.md. --}}
    <style>
        :root { font-family: 'InterVariable', ui-sans-serif, system-ui, sans-serif;
                font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11', 'ss01', 'ss03'; }
        @supports not (font-variation-settings: normal) {
            :root { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        }
    </style>
    @if($qiThemeEnabled)
        {{-- Theme init — MUST run before <body> paints to avoid FOIT. Single
             owner of matchMedia + localStorage + html.dark/dataset.theme.
             Toggle dispatches `qi-theme-change`; this script applies and
             dispatches `qi-theme-applied` for the toggle to mirror.
             See internal/specs/dashboard-dark-mode.md §1.3. --}}
        <script>
            (function () {
                var KEY = 'qi-theme';
                var root = document.documentElement;

                function readPref() {
                    try {
                        var v = localStorage.getItem(KEY);
                        return (v === 'light' || v === 'dark') ? v : 'system';
                    } catch (e) { return 'system'; }
                }
                function systemPrefersDark() {
                    return !!(window.matchMedia
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);
                }
                function apply(pref) {
                    root.dataset.theme = pref;
                    var dark = pref === 'dark' || (pref === 'system' && systemPrefersDark());
                    root.classList.toggle('dark', dark);
                    // Detail carries BOTH preference (light/dark/system) and the
                    // resolved scheme (light/dark) so listeners that need to
                    // know what's actually rendered (e.g. a third-party widget
                    // syncing its own theme) don't have to read html.dark.
                    window.dispatchEvent(new CustomEvent('qi-theme-applied', {
                        detail: { preference: pref, resolved: dark ? 'dark' : 'light' },
                    }));
                }

                apply(readPref());

                // Single matchMedia subscription — installed once per full
                // document load. Survives wire:navigate (head is not morphed).
                if (window.matchMedia) {
                    window.matchMedia('(prefers-color-scheme: dark)')
                        .addEventListener('change', function () {
                            if ((root.dataset.theme || 'system') === 'system') {
                                apply('system');
                            }
                        });
                }

                window.addEventListener('qi-theme-change', function (e) {
                    var pref = (e && e.detail) || 'system';
                    try { localStorage.setItem(KEY, pref); } catch (err) {}
                    apply(pref);
                });

                // wire:navigate morphs <body> AND replaces <html> attributes
                // with the freshly-fetched response's, wiping the runtime-set
                // `dark` class + `data-theme` dataset. Re-apply from
                // localStorage after each navigation so the theme survives
                // the connection-scope picker and any other in-app link.
                window.addEventListener('livewire:navigated', function () {
                    apply(readPref());
                });
            })();
        </script>
    @endif
    @if($qiClockEnabled)
        {{-- Clock-format init — same single-owner pattern as the theme
             script above. Owns localStorage['qi-clock'] +
             `documentElement.dataset.clock`. Toggle dispatches
             `qi-clock-change`; this script applies the preference and
             dispatches `qi-clock-applied` for the qi-time hydrator to
             rebuild its `Intl.DateTimeFormat`. Three values: '12h' (force
             AM/PM), '24h' (force 24-hour), 'auto' (follow browser locale —
             en-US → AM/PM, en-GB / OS 24-hour toggle → 24-hour). --}}
        <script>
            (function () {
                var KEY = 'qi-clock';
                var ALLOWED = { '12h': 1, '24h': 1, 'auto': 1 };
                var root = document.documentElement;

                function readPref() {
                    try {
                        var v = localStorage.getItem(KEY);
                        return ALLOWED[v] ? v : 'auto';
                    } catch (e) { return 'auto'; }
                }
                function apply(pref) {
                    root.dataset.clock = pref;
                    window.dispatchEvent(new CustomEvent('qi-clock-applied', {
                        detail: { preference: pref },
                    }));
                }

                apply(readPref());

                window.addEventListener('qi-clock-change', function (e) {
                    var pref = (e && e.detail && ALLOWED[e.detail]) ? e.detail : 'auto';
                    try { localStorage.setItem(KEY, pref); } catch (err) {}
                    apply(pref);
                });

                // wire:navigate replaces <html> attributes; re-apply from
                // localStorage after each navigation so the preference
                // survives in-app links. Mirrors the theme-init handler.
                window.addEventListener('livewire:navigated', function () {
                    apply(readPref());
                });
            })();
        </script>
    @endif
    {{-- Inter variable font — non-blocking fetch. Sits AFTER the theme-init
         script so a slow/blocked rsms.me does not stall `html.dark`
         application. preload+onload swaps in the stylesheet once it's
         downloaded; <noscript> keeps SR/no-JS hosts working; system-ui
         is already painting from the inline :root rule above. --}}
    <link rel="preconnect" href="https://rsms.me/" crossorigin>
    <link rel="preload" as="style" href="https://rsms.me/inter/inter.css"
          onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://rsms.me/inter/inter.css"></noscript>
    {{-- Tailwind v3 Play CDN. Spec hard-depends on:
           1. inline `tailwind.config = { darkMode: 'class' }` working
           2. `dark:` ancestor selector triggered by `html.dark`
         Both are stable v3 contracts. v4 has no Play CDN equivalent —
         migrating off this URL requires a build step + porting darkMode
         and the variant to `@custom-variant`. See dark-mode spec §1.1. --}}
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- darkMode + safelist — emitted unconditionally even when the theme
         flag is off so `dark:` variants stay in `class` mode (not the
         default `media` mode). Without this, any `dark:` class added by
         Phase 2-5 audits would auto-fire on system-dark hosts during the
         audit window, exposing half-themed surfaces. With darkMode='class'
         and no `.dark` ancestor (because the head script above didn't run
         when the flag was off), `dark:` variants are inert.

         The safelist guarantees the JSON-colorizer dual-class strings
         (only present as JS string literals in highlightJson below) survive
         any extractor changes in future Play CDN versions. --}}
    <script>
        tailwind.config = {
            darkMode: 'class',
            safelist: [
                'text-blue-700',  'dark:text-blue-300',
                'text-green-700', 'dark:text-green-300',
                'text-purple-700','dark:text-purple-300',
                'text-orange-700','dark:text-orange-300',
            ],
        };
    </script>
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }

        /* Copy-button feedback. Driven by `data-qi-copied` attribute toggled in
           layouts/app.blade.php's click handler. Inlined here (not via Tailwind
           arbitrary variants) because the CDN JIT can miss `group-data-[…]:*` in
           anonymous Blade components, leaving the swap dead. */
        [data-qi-copy] .qi-copy-check { display: none; }
        [data-qi-copy][data-qi-copied] .qi-copy-icon { display: none; }
        [data-qi-copy][data-qi-copied] .qi-copy-check { display: inline-block; }
        [data-qi-copy][data-qi-copied] .qi-copy-text { display: none; }
        [data-qi-copy][data-qi-copied] .qi-copy-text-copied { display: inline; }
        [data-qi-copy] .qi-copy-text-copied { display: none; }
        [data-qi-copy][data-qi-copied] {
            background-color: rgb(236 253 245);
            color: rgb(4 120 87);
            box-shadow: inset 0 0 0 1px rgb(5 150 105 / 0.3);
        }
        /* Dark-mode copy-feedback. Inert without `.dark` on <html>; emitted
           unconditionally because the selector cost is negligible. */
        html.dark [data-qi-copy][data-qi-copied] {
            background-color: rgb(6 78 59 / 0.4);
            color: rgb(110 231 183);
            box-shadow: inset 0 0 0 1px rgb(52 211 153 / 0.3);
        }

        /* qi-time tooltip — single floating element managed by the global
           handler in <head>. Pinned above (or below) the trigger, never
           captures pointer events so hover doesn't flicker on slow paint. */
        #qi-time-tooltip {
            position: fixed;
            z-index: 60;
            pointer-events: none;
            padding: 6px 8px;
            border-radius: 6px;
            background: rgb(17 24 39);
            color: white;
            font-size: 11px;
            line-height: 1.35;
            box-shadow: 0 8px 24px -8px rgb(0 0 0 / 0.4), inset 0 0 0 1px rgb(255 255 255 / 0.08);
            white-space: nowrap;
            opacity: 0;
            transform: translateY(2px);
            transition: opacity 90ms ease, transform 90ms ease;
        }
        #qi-time-tooltip[data-shown] { opacity: 1; transform: translateY(0); }
        #qi-time-tooltip table { border-collapse: collapse; }
        #qi-time-tooltip td { padding: 1px 0; vertical-align: baseline; }
        #qi-time-tooltip td.qi-time-label {
            padding-right: 10px;
            color: rgb(156 163 175);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 10px;
        }
        #qi-time-tooltip td.qi-time-value { font-variant-numeric: tabular-nums; }
        /* Dark-mode tooltip surface — slightly lighter than the body
           bg-gray-950 so the tooltip separates from the page. gray-700
           (rgb 55 65 81) picked over gray-800 because gray-800 reads as
           "same as a card". Inert without `.dark` on <html>. */
        html.dark #qi-time-tooltip {
            background: rgb(55 65 81);
        }
    </style>
    {{--
        Inline JSON colorizer + copy-to-clipboard helpers for the Details modal.
        Placed in <head> so the Livewire hook registers before `@livewireScripts` boots
        (see Resolved Q #14 in specs/structured-details-modal.md). Dual-branch guard
        tolerates placement-order refactors.

        SECURITY — do not reorder the escape chain in highlightJson(). The HTML-escape
        step (& → &amp;, < → &lt;, > → &gt;) MUST run before token-wrapping spans, or
        hostile payload content round-trips through textContent → insertAdjacentHTML
        and executes as markup. See §3 "Invariant — do not reorder" in the spec.
    --}}
    <script>
        function highlightJson(src) {
            return src
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(
                    /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
                    function (m, str, colon, kw, num) {
                        if (str) {
                            return colon
                                ? '<span class="text-blue-700 dark:text-blue-300">' + str + '</span>' + colon
                                : '<span class="text-green-700 dark:text-green-300">' + str + '</span>';
                        }
                        if (kw)  { return '<span class="text-purple-700 dark:text-purple-300">' + kw + '</span>'; }
                        if (num) { return '<span class="text-orange-700 dark:text-orange-300">' + num + '</span>'; }
                        return m;
                    }
                );
        }

        function registerQueueInsightsHook() {
            var colorize = function (payload) {
                var el = payload && payload.el ? payload.el : document;
                el.querySelectorAll('[data-json-highlight]').forEach(function (node) {
                    var src = node.textContent;
                    // Full-string idempotency guard — expando property, not data-attribute,
                    // to keep the ~16KB body string out of serialized DOM dumps.
                    if (node._qiColorizedSrc === src) return;

                    var escaped = highlightJson(src);
                    node.replaceChildren();
                    node.insertAdjacentHTML('afterbegin', escaped);
                    node._qiColorizedSrc = src;
                });
            };
            // `morph.updated` covers in-place updates (poll re-renders);
            // `morph.added` covers element INSERTIONS like the modal-
            // open path (a `selectedPayloadId` null-guard switching
            // from false to true mounts a new node, which fires
            // `morph.added`, not `morph.updated`). Both hooks needed
            // to avoid the colorizer skipping a newly-opened modal's
            // JSON pane.
            Livewire.hook('morph.updated', colorize);
            Livewire.hook('morph.added', colorize);
        }

        // Dual-branch guard — works whether script runs before or after Livewire bootstrap.
        if (window.Livewire) {
            registerQueueInsightsHook();
        } else {
            document.addEventListener('livewire:initialized', registerQueueInsightsHook, { once: true });
        }

        // Copy-to-clipboard with Selection-API fallback for non-HTTPS dev environments.
        // Capture-phase listener (third arg `true`) — modal inner panel runs Alpine's
        // `@click.stop` to keep clicks inside the modal, which kills bubble-phase
        // document handlers. Capture phase fires first regardless.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-qi-copy]');
            if (! btn) return;

            var targetId = btn.getAttribute('data-qi-copy-target');
            var target = targetId ? document.getElementById(targetId) : null;
            if (! target) return;

            var text = target.textContent || '';

            // Visual feedback on the button itself — toggle `data-qi-copied`, which
            // the inline CSS uses to flip icon visibility, label text, and bg.
            function flashButton() {
                btn.setAttribute('data-qi-copied', '');
                if (btn._qiCopyTimer) clearTimeout(btn._qiCopyTimer);
                btn._qiCopyTimer = setTimeout(function () {
                    btn.removeAttribute('data-qi-copied');
                }, 1600);
            }

            function selectFallback() {
                try {
                    var range = document.createRange();
                    range.selectNode(target);
                    var sel = window.getSelection();
                    if (sel) {
                        sel.removeAllRanges();
                        sel.addRange(range);
                        flashButton();
                    }
                } catch (err) {
                    /* swallow — button stays in its idle state */
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(flashButton, selectFallback);
            } else {
                selectFallback();
            }
        }, true);

        /*
         * qi-time hydration — every <time data-qi-time> element gets:
         *   1. Absolute formats rewritten to the user's local timezone.
         *   2. A hover/focus tooltip with both UTC and Local lines.
         *
         * Server emits ISO-8601 UTC in `datetime`. Browser parses it into a
         * Date and re-formats with Intl. Re-runs after every Livewire
         * `morph.updated` so freshly polled rows hydrate the same way.
         *
         * Relative formats keep their server-rendered text (diffForHumans is
         * timezone-agnostic) — only the tooltip is added.
         */
        (function () {
            var BASE_FMT = { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' };
            var localFormatter, utcFormatter;

            // Build the pair of formatters honouring the active clock
            // preference (`documentElement.dataset.clock` — owned by the
            // clock-init head script). 'auto' (or missing) leaves `hour12`
            // unset so the locale's default applies; '12h' / '24h' force
            // the chosen cycle. Rebuilt on `qi-clock-applied` so a toggle
            // click flows through to every <time> element.
            function readClockOpts() {
                var pref = (document.documentElement.dataset.clock || 'auto');
                var opts = Object.assign({}, BASE_FMT);
                if (pref === '12h') { opts.hour12 = true; }
                else if (pref === '24h') { opts.hour12 = false; }
                return opts;
            }
            function buildFormatters() {
                var opts = readClockOpts();
                try {
                    localFormatter = new Intl.DateTimeFormat(undefined, opts);
                    utcFormatter = new Intl.DateTimeFormat(undefined, Object.assign({ timeZone: 'UTC' }, opts));
                    return true;
                } catch (e) {
                    return false;
                }
            }
            if (! buildFormatters()) {
                return; // ancient browser — leave server-rendered text alone
            }

            function localOffsetLabel() {
                var min = -new Date().getTimezoneOffset();
                if (min === 0) return 'UTC';
                var sign = min >= 0 ? '+' : '-';
                var abs = Math.abs(min);
                var h = Math.floor(abs / 60);
                var m = abs % 60;
                return 'UTC' + sign + h + (m ? ':' + String(m).padStart(2, '0') : '');
            }
            var OFFSET_LABEL = localOffsetLabel();

            function hydrateOne(el) {
                if (el._qiTimeHydrated === el.getAttribute('datetime')) return;
                var iso = el.getAttribute('datetime');
                if (! iso) return;
                var d = new Date(iso);
                if (isNaN(d.getTime())) return;
                var fmt = el.getAttribute('data-qi-time-format') || 'relative';
                var prefix = el.getAttribute('data-qi-time-prefix');
                if (fmt === 'absolute' || fmt === 'absolute-mono') {
                    el.textContent = (prefix ? prefix + ' ' : '') + localFormatter.format(d);
                }
                // Refresh aria-label so SR users hear the same UTC + Local
                // pair the visual tooltip shows. Server-side aria-label is
                // UTC-only because the offset is unknown; JS knows both.
                el.setAttribute(
                    'aria-label',
                    (prefix ? prefix + ' ' : '') +
                    utcFormatter.format(d) + ' UTC, ' +
                    localFormatter.format(d) + ' ' + OFFSET_LABEL
                );
                el._qiTimeHydrated = iso;
            }

            function hydrateAll(root) {
                (root || document).querySelectorAll('time[data-qi-time]').forEach(hydrateOne);
            }

            // Tooltip — single shared element. Has a stable id so triggers
            // can point at it via aria-describedby while shown.
            var TIP_ID = 'qi-time-tooltip';
            var tip = null;
            var currentTrigger = null;
            function ensureTip() {
                if (tip) return tip;
                tip = document.createElement('div');
                tip.id = TIP_ID;
                tip.setAttribute('role', 'tooltip');
                tip.innerHTML = '<table><tbody>' +
                    '<tr><td class="qi-time-label">UTC</td><td class="qi-time-value" data-qi-tip-utc></td></tr>' +
                    '<tr><td class="qi-time-label" data-qi-tip-local-label></td><td class="qi-time-value" data-qi-tip-local></td></tr>' +
                    '</tbody></table>';
                document.body.appendChild(tip);
                return tip;
            }

            function showTip(el) {
                var iso = el.getAttribute('datetime');
                if (! iso) return;
                var d = new Date(iso);
                if (isNaN(d.getTime())) return;
                var t = ensureTip();
                t.querySelector('[data-qi-tip-utc]').textContent = utcFormatter.format(d);
                t.querySelector('[data-qi-tip-local]').textContent = localFormatter.format(d);
                t.querySelector('[data-qi-tip-local-label]').textContent = 'Local ' + OFFSET_LABEL;
                t.style.left = '0px';
                t.style.top = '0px';
                t.setAttribute('data-shown', '');
                // Wire the trigger to the tooltip so a screen reader announces
                // it on focus. Stash any pre-existing aria-describedby so we
                // can restore it on hide rather than clobber a host value.
                if (currentTrigger && currentTrigger !== el) {
                    restoreDescribedBy(currentTrigger);
                }
                currentTrigger = el;
                el._qiPrevDescribedBy = el.getAttribute('aria-describedby');
                el.setAttribute('aria-describedby', TIP_ID);
                // Position after paint so width is known. Bail if the trigger
                // was detached (Livewire morph) between mouseover and rAF —
                // a detached node returns rect 0,0,0,0 and would pin the tip
                // to the top-left corner.
                requestAnimationFrame(function () {
                    if (! el.isConnected) { hideTip(); return; }
                    var rect = el.getBoundingClientRect();
                    var tw = t.offsetWidth;
                    var th = t.offsetHeight;
                    var left = rect.left + (rect.width / 2) - (tw / 2);
                    left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
                    var top = rect.top - th - 6;
                    if (top < 8) top = rect.bottom + 6; // flip below if no space above
                    t.style.left = left + 'px';
                    t.style.top = top + 'px';
                });
            }

            function restoreDescribedBy(el) {
                if (el._qiPrevDescribedBy) {
                    el.setAttribute('aria-describedby', el._qiPrevDescribedBy);
                } else {
                    el.removeAttribute('aria-describedby');
                }
                el._qiPrevDescribedBy = null;
            }

            function hideTip() {
                if (tip) tip.removeAttribute('data-shown');
                if (currentTrigger) {
                    restoreDescribedBy(currentTrigger);
                    currentTrigger = null;
                }
            }

            document.addEventListener('mouseover', function (e) {
                var el = e.target.closest && e.target.closest('time[data-qi-time]');
                if (el) showTip(el);
            });
            document.addEventListener('mouseout', function (e) {
                var el = e.target.closest && e.target.closest('time[data-qi-time]');
                if (el) hideTip();
            });
            document.addEventListener('focusin', function (e) {
                var el = e.target.closest && e.target.closest('time[data-qi-time]');
                if (el) showTip(el);
            });
            document.addEventListener('focusout', function (e) {
                var el = e.target.closest && e.target.closest('time[data-qi-time]');
                if (el) hideTip();
            });
            window.addEventListener('scroll', hideTip, true);

            // Clock-preference change → rebuild formatters with the new
            // `hour12` setting and re-stamp every visible <time> element.
            // The `_qiTimeHydrated` cache is cleared so hydrateAll re-runs
            // the absolute-format rewrite for already-hydrated nodes.
            window.addEventListener('qi-clock-applied', function () {
                if (! buildFormatters()) return;
                document.querySelectorAll('time[data-qi-time]').forEach(function (el) {
                    el._qiTimeHydrated = null;
                });
                hydrateAll(document);
                // Refresh the tooltip in place when one is currently shown.
                if (tip && tip.hasAttribute('data-shown') && currentTrigger) {
                    showTip(currentTrigger);
                }
            });

            function registerLivewireHook() {
                if (! window.Livewire || ! window.Livewire.hook) return;
                var rehydrate = function (payload) {
                    var el = payload && payload.el ? payload.el : document;
                    hydrateAll(el);
                    // Defensive: if the hovered <time> was replaced mid-show,
                    // mouseout may not fire on the old node. Hide the tip so
                    // it doesn't pin to a stale viewport coordinate.
                    hideTip();
                };
                // `morph.updated` catches in-place updates (poll re-renders
                // that don't change DOM identity). `morph.added` catches
                // element INSERTIONS — the path the pending / failed /
                // batch / schedule modals take when their `selected*`
                // null-guard flips from false to true. Without the `added` hook,
                // a freshly-opened modal's `<time data-qi-time>` elements
                // never run through the locale + clock-pref rewrite and
                // stay stuck on the server-side 12h `g:i A` fallback —
                // exactly the "I press 24h and nothing changes" symptom.
                Livewire.hook('morph.updated', rehydrate);
                Livewire.hook('morph.added', rehydrate);
            }
            function bootQiTime() {
                hydrateAll(document);
                // Dual-branch: register the Livewire hook whether scripts load
                // before or after Livewire bootstrap. Mirrors the JSON colorizer
                // pattern above; without this, polled morphs leave absolute-mode
                // times stuck in the server's timezone.
                if (window.Livewire) {
                    registerLivewireHook();
                } else {
                    document.addEventListener('livewire:initialized', registerLivewireHook, { once: true });
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bootQiTime, { once: true });
            } else {
                bootQiTime();
            }
        })();
    </script>
</head>
<body @class([
    'bg-gray-50 text-gray-900 antialiased',
    'dark:bg-gray-950 dark:text-gray-100' => $qiThemeEnabled,
])>
    <div class="isolate min-h-dvh">
        {{-- Horizon-style dark top bar — signature "infra tool" chrome. The
             `header-scope` stack lets the dashboard inject a connection-scope
             picker between the brand and the polling chip. --}}
        <header class="bg-gray-900">
            <div class="mx-auto flex flex-wrap items-center gap-x-4 gap-y-2 px-6 py-4 sm:px-8 lg:max-w-7xl lg:px-10">
                <a href="/" aria-label="Homepage" class="flex items-center gap-2.5 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-400">
                    {{-- Mark — four ascending bars on an emerald gradient, reads as "queue depth". --}}
                    <span class="inline-flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-emerald-400 to-emerald-500 text-white shadow-sm ring-1 ring-emerald-300/20">
                        <svg class="size-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <rect x="1" y="10" width="2.5" height="5" rx="0.75"/>
                            <rect x="5" y="7" width="2.5" height="8" rx="0.75"/>
                            <rect x="9" y="4" width="2.5" height="11" rx="0.75"/>
                            <rect x="13" y="1" width="2.5" height="14" rx="0.75"/>
                        </svg>
                    </span>
                    <span class="text-base font-semibold tracking-tight text-white">Queue Insights</span>
                </a>

                @stack('header-scope')

                @php($qiPolling = \SanderMuller\QueueInsights\Support\Config::bool('dashboard.polling', true))
                {{-- Right-edge controls: theme toggle + polling chip. Wrapped
                     in `ml-auto` so they hug the right side regardless of
                     header-scope stack contents. Toggle only renders when
                     the theme flag is on (Phase 6 flips the default to true). --}}
                <div class="ml-auto flex items-center gap-2">
                    @if($qiClockEnabled)
                        <x-queue-insights::clock-toggle/>
                    @endif
                    @if($qiThemeEnabled)
                        <x-queue-insights::theme-toggle/>
                    @endif
                    <div class="flex items-center gap-2 rounded-full bg-white/5 px-3 py-1 text-xs font-medium text-gray-300 ring-1 ring-inset ring-white/10">
                        <span class="relative flex size-2">
                            @if($qiPolling)
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            @endif
                            <span class="relative inline-flex size-2 rounded-full {{ $qiPolling ? 'bg-emerald-400' : 'bg-gray-500' }}"></span>
                        </span>
                        <span>{{ $qiPolling ? 'Active · polling 10s' : 'Static · polling off' }}</span>
                    </div>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-6 py-8 sm:px-8 lg:px-10">
            {{ $slot ?? '' }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
