{{-- Renders a Laravel queue payload as grouped panels:
    Job config · Execution · Tags · Serialized command · Other fields.

    Props:
      $payload — array<string, mixed> | string | null
        - array: decoded Laravel queue payload structure
        - string: raw body that failed json_decode (rendered inside <pre>)
        - null: nothing to render

    Re-used by both the completed-jobs Section C Raw tab and the failed-jobs payload section. --}}
@props(['payload' => null])

@php
    $body = is_string($payload) ? (json_decode($payload, true) ?? $payload) : $payload;

    // Hover-tooltips for queue-internal field names. `<abbr title>` is browser-native
    // so we get screen-reader announcements + a real OS-level tooltip with no JS.
    $fieldHelp = [
        'maxTries' => 'Total attempts allowed before the job is marked failed (Laravel `tries` property).',
        'maxExceptions' => 'Max exceptions allowed before stopping retries even if maxTries not yet reached.',
        'timeout' => 'Per-attempt seconds before the worker SIGTERMs the job (Laravel `timeout` property).',
        'backoff' => 'Seconds to wait between retries. List = staircase delays (e.g. [1,5,10] = 1s · 5s · 10s).',
        'retryUntil' => 'Absolute timestamp after which the job stops retrying regardless of maxTries.',
        'failOnTimeout' => 'When true, a timeout marks the job as failed instead of letting it retry.',
        'attempts' => 'How many times the worker has tried this job so far.',
        'delay' => 'Seconds the job was delayed before its first dispatch.',
        'createdAt' => 'When the job payload was constructed.',
        'pushedAt' => 'When the job was pushed to the queue connection.',
    ];
@endphp

@if (is_array($body))
    @php
        $configKeys = ['maxTries', 'maxExceptions', 'timeout', 'backoff', 'retryUntil', 'failOnTimeout'];
        $executionKeys = ['attempts', 'delay', 'createdAt', 'pushedAt'];
        $wellKnownKeys = array_merge(
            ['uuid', 'displayName', 'job', 'id', 'type', 'tags', 'silenced', 'data'],
            $configKeys,
            $executionKeys,
        );
        $presentConfig = array_values(array_filter($configKeys, fn ($k): bool => array_key_exists($k, $body)));
        $presentExecution = array_values(array_filter($executionKeys, fn ($k): bool => array_key_exists($k, $body)));
        $tags = is_array($body['tags'] ?? null) ? $body['tags'] : [];
        $dataCommand = is_array($body['data'] ?? null) ? ($body['data']['command'] ?? null) : null;
        $dataCommandName = is_array($body['data'] ?? null) ? ($body['data']['commandName'] ?? null) : null;

        // Best-effort decode of the PHP-serialized job instance to surface its
        // public/protected/private property values without running __wakeup.
        $instanceData = is_string($dataCommand) && $dataCommand !== ''
            ? \SanderMuller\QueueInsights\Support\SerializedCommandReader::extract($dataCommand)
            : null;

        // Detect old streams written before the sanitizer skipped truncation on
        // serialized blobs — `…[truncated]` marker on the tail signals we can't extract.
        $instanceTruncated = is_string($dataCommand) && str_ends_with($dataCommand, '…[truncated]');
        $otherKeys = array_values(array_diff(array_keys($body), $wellKnownKeys));

        $renderConfigValue = function (string $key, mixed $v): string {
            if ($v === null) {
                return 'null';
            }
            if (is_bool($v)) {
                return $v ? 'true' : 'false';
            }
            if ($key === 'timeout' && is_numeric($v)) {
                return ((int) $v) . ' s';
            }
            if ($key === 'backoff' && is_array($v)) {
                return implode(', ', array_map(fn ($x): string => (string) $x, $v)) . ' s';
            }
            if (is_scalar($v)) {
                return (string) $v;
            }

            return (string) json_encode($v, JSON_UNESCAPED_SLASHES);
        };

        $renderTimestamp = function (mixed $v): ?array {
            if (! is_numeric($v)) {
                return null;
            }
            try {
                $dt = \Illuminate\Support\Facades\Date::createFromTimestamp((float) $v);

                return ['human' => $dt->diffForHumans(), 'abs' => $dt->toIso8601String()];
            } catch (\Throwable) {
                return null;
            }
        };
    @endphp
    <div class="space-y-3">
        {{-- Job config --}}
        @if (count($presentConfig) > 0)
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                <p class="border-b border-gray-950/5 px-4 py-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Job config</p>
                <dl class="divide-y divide-gray-950/5">
                    @foreach ($presentConfig as $k)
                        <div class="grid grid-cols-[max-content_1fr] gap-x-4 px-4 py-2 text-xs">
                            <dt class="font-mono font-medium text-gray-600">
                                @if (isset($fieldHelp[$k]))
                                    <abbr title="{{ $fieldHelp[$k] }}" class="cursor-help decoration-gray-300 decoration-dotted underline-offset-2 [text-decoration-line:underline]">{{ $k }}</abbr>
                                @else
                                    {{ $k }}
                                @endif
                            </dt>
                            <dd class="break-all font-mono {{ $body[$k] === null ? 'text-gray-400' : 'text-gray-900' }}">{{ $renderConfigValue($k, $body[$k]) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        {{-- Execution state --}}
        @if (count($presentExecution) > 0)
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                <p class="border-b border-gray-950/5 px-4 py-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Execution</p>
                <dl class="divide-y divide-gray-950/5">
                    @foreach ($presentExecution as $k)
                        @php
                            $v = $body[$k];
                            $ts = in_array($k, ['createdAt', 'pushedAt'], true) ? $renderTimestamp($v) : null;
                        @endphp
                        <div class="grid grid-cols-[max-content_1fr] gap-x-4 px-4 py-2 text-xs">
                            <dt class="font-mono font-medium text-gray-600">
                                @if (isset($fieldHelp[$k]))
                                    <abbr title="{{ $fieldHelp[$k] }}" class="cursor-help decoration-gray-300 decoration-dotted underline-offset-2 [text-decoration-line:underline]">{{ $k }}</abbr>
                                @else
                                    {{ $k }}
                                @endif
                            </dt>
                            <dd class="break-all font-mono {{ $v === null ? 'text-gray-400' : 'text-gray-900' }}">
                                @if ($ts !== null)
                                    <span>{{ $ts['human'] }}</span>
                                    <span class="ml-1 text-[10px] text-gray-400" title="{{ $ts['abs'] }}">({{ $v }})</span>
                                @else
                                    {{ $v === null ? 'null' : (is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES)) }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        {{-- Tags --}}
        @if (count($tags) > 0)
            <div class="rounded-lg bg-white px-4 py-3 ring-1 ring-gray-950/5">
                <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Tags</p>
                <ul role="list" class="flex flex-wrap gap-1.5">
                    @foreach ($tags as $tag)
                        <li class="rounded bg-gray-950/5 px-2 py-0.5 font-mono text-[11px] text-gray-700 ring-1 ring-inset ring-gray-950/5">{{ is_scalar($tag) ? (string) $tag : (string) json_encode($tag, JSON_UNESCAPED_SLASHES) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Job instance — explanatory note when the serialized blob was truncated by
            an older sanitizer config (max_field_bytes hit). New stream entries written
            after the sanitizer fix preserve serialized blobs intact. --}}
        @if ($instanceTruncated && $instanceData === null)
            <div class="rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-900 ring-1 ring-inset ring-amber-600/20">
                <p class="font-medium">Job instance unavailable</p>
                <p class="mt-1 text-amber-800">The serialized command was truncated when this entry was captured (older sanitizer config). New entries will surface the full instance data.</p>
            </div>
        @endif

        {{-- Job instance data — extracted properties from the serialized command.
            Visible by default since this is usually what you actually want to see. --}}
        @if ($instanceData !== null && count($instanceData['properties']) > 0)
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                <div class="flex items-center justify-between border-b border-gray-950/5 px-4 py-2">
                    <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500">Job instance</p>
                    @if ($instanceData['class'] !== null)
                        <p class="break-all font-mono text-[11px] text-gray-500">{{ $instanceData['class'] }}</p>
                    @endif
                </div>
                <x-queue-insights::serialized-properties :properties="$instanceData['properties']"/>
            </div>
        @endif

        {{-- Raw serialized command — collapsed by default. Only useful for low-level debug. --}}
        @if (is_string($dataCommand) && $dataCommand !== '')
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5" x-data="{ expanded: false }">
                <div class="flex items-center justify-between border-b border-gray-950/5 px-4 py-2">
                    <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500">Serialized command</p>
                    <button type="button"
                            @click="expanded = ! expanded"
                            class="rounded bg-gray-950/5 px-2 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-950/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <span x-show="! expanded">show</span>
                        <span x-show="expanded" x-cloak>hide</span>
                    </button>
                </div>
                <div class="px-4 py-2 text-xs">
                    @if ($dataCommandName)
                        <p class="font-mono text-gray-600">{{ $dataCommandName }}</p>
                    @endif
                    <p class="mt-0.5 text-[10px] text-gray-500 tabular-nums">PHP-serialized object, {{ number_format(strlen($dataCommand)) }} bytes</p>
                    <pre x-show="expanded" x-cloak class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded bg-gray-50 p-2 font-mono text-[11px] text-gray-800 ring-1 ring-inset ring-gray-950/10">{{ $dataCommand }}</pre>
                </div>
            </div>
        @endif

        {{-- Other (non-standard) fields. Containers (assoc arrays / lists)
            render through the recursive `nested-data` component — same drill-
            into-tree UX Sentry uses for "Additional Data". Scalars stay
            inline with truncate-and-expand for long strings. --}}
        @if (count($otherKeys) > 0)
            <div class="rounded-lg bg-white ring-1 ring-gray-950/5">
                <p class="border-b border-gray-950/5 px-4 py-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Other fields</p>
                <dl class="divide-y divide-gray-950/5">
                    @foreach ($otherKeys as $k)
                        @php
                            $v = $body[$k];
                            $isContainer = is_array($v) && $v !== [];
                            $rendered = $isContainer ? '' : (is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES));
                            $truncated = ! $isContainer && strlen($rendered) > 200;
                        @endphp
                        <div class="@if (! $isContainer) grid grid-cols-[max-content_1fr] gap-x-4 @endif px-4 py-2 text-xs"
                             @if ($truncated) x-data="{ expanded: false }" @endif>
                            <dt class="font-mono font-medium text-gray-600 {{ $isContainer ? 'mb-1 block' : '' }}">
                                @if (isset($fieldHelp[$k]))
                                    <abbr title="{{ $fieldHelp[$k] }}" class="cursor-help decoration-gray-300 decoration-dotted underline-offset-2 [text-decoration-line:underline]">{{ $k }}</abbr>
                                @else
                                    {{ $k }}
                                @endif
                            </dt>
                            <dd class="{{ $isContainer ? '-mx-4 mt-1 border-t border-gray-950/5 bg-gray-950/[0.02]' : 'break-all font-mono ' . ($v === null ? 'text-gray-400' : 'text-gray-900') }}">
                                @if ($isContainer)
                                    <x-queue-insights::nested-data :data="$v"/>
                                @elseif ($truncated)
                                    <span x-show="! expanded">{{ substr($rendered, 0, 200) }}…</span>
                                    <span x-show="expanded" x-cloak>{{ $rendered }}</span>
                                    <button type="button" @click="expanded = ! expanded"
                                            class="ml-1 rounded bg-gray-950/5 px-1.5 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-950/10">
                                        <span x-show="! expanded">expand</span>
                                        <span x-show="expanded" x-cloak>collapse</span>
                                    </button>
                                @else
                                    {{ $v === null ? 'null' : $rendered }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>
@elseif (is_string($body) && $body !== '')
    <div class="rounded-lg bg-white p-4 ring-1 ring-gray-950/5">
        <p class="mb-2 text-xs text-gray-500">Raw body (not JSON-decodable):</p>
        <pre class="whitespace-pre-wrap break-all font-mono text-xs text-gray-900">{{ $body }}</pre>
    </div>
@endif
