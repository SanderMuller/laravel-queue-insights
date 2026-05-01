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
