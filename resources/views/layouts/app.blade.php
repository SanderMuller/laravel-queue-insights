<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Queue Insights</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                                ? '<span class="text-blue-700">' + str + '</span>' + colon
                                : '<span class="text-green-700">' + str + '</span>';
                        }
                        if (kw)  { return '<span class="text-purple-700">' + kw + '</span>'; }
                        if (num) { return '<span class="text-orange-700">' + num + '</span>'; }
                        return m;
                    }
                );
        }

        function registerQueueInsightsHook() {
            Livewire.hook('morph.updated', function (payload) {
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
            });
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
            var ABS_FMT = { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' };
            var localFormatter, utcFormatter;
            try {
                localFormatter = new Intl.DateTimeFormat(undefined, ABS_FMT);
                utcFormatter = new Intl.DateTimeFormat(undefined, Object.assign({ timeZone: 'UTC' }, ABS_FMT));
            } catch (e) {
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

            function registerLivewireHook() {
                if (! window.Livewire || ! window.Livewire.hook) return;
                Livewire.hook('morph.updated', function (payload) {
                    var el = payload && payload.el ? payload.el : document;
                    hydrateAll(el);
                    // Defensive: if the hovered <time> was replaced mid-show,
                    // mouseout may not fire on the old node. Hide the tip so
                    // it doesn't pin to a stale viewport coordinate.
                    hideTip();
                });
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
<body class="bg-gray-50 text-gray-900 antialiased">
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
                <div class="ml-auto flex items-center gap-2 rounded-full bg-white/5 px-3 py-1 text-xs font-medium text-gray-300 ring-1 ring-inset ring-white/10">
                    <span class="relative flex size-2">
                        @if($qiPolling)
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        @endif
                        <span class="relative inline-flex size-2 rounded-full {{ $qiPolling ? 'bg-emerald-400' : 'bg-gray-500' }}"></span>
                    </span>
                    <span>{{ $qiPolling ? 'Active · polling 10s' : 'Static · polling off' }}</span>
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
