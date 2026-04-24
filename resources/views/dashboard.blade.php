@extends('queue-insights::layouts.app')

@section('content')
<div wire:poll.10s class="space-y-8">

    {{-- Queue cards --}}
    <section>
        <h2 class="mb-3 text-lg font-semibold">Queues</h2>
        @if (count($queues) === 0)
            <div class="rounded border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                No queues configured. Add entries to <code>config/queue-insights.php</code> under <code>snapshots</code>.
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($queues as $q)
                    <div class="rounded border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-sm text-gray-500">{{ $q['connection'] }}</div>
                                <div class="font-mono text-base">{{ $q['queue'] }}</div>
                            </div>
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs uppercase tracking-wide text-gray-700">
                                {{ $q['driver'] }}
                            </span>
                        </div>

                        <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                            <div>
                                <dt class="text-xs text-gray-500">Depth</dt>
                                <dd class="text-xl font-semibold">{{ $q['depth'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">In-flight</dt>
                                <dd class="text-xl font-semibold">{{ $q['inflight'] ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Delayed</dt>
                                <dd class="text-xl font-semibold">{{ $q['delayed'] ?? '—' }}</dd>
                            </div>
                        </dl>

                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            @if ($q['error'])
                                <span class="rounded bg-red-100 px-2 py-0.5 text-red-700" title="{{ $q['error'] }}">
                                    error
                                </span>
                            @endif
                            @if ($q['stale'])
                                <span class="rounded bg-yellow-100 px-2 py-0.5 text-yellow-700">stale</span>
                            @endif
                            @if ($q['last_at'])
                                <span class="text-gray-500">last {{ $q['last_at']->diffForHumans() }}</span>
                            @else
                                <span class="text-gray-500">no snapshot yet</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Job classes --}}
    <section>
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Job classes (24h)</h2>
            @if ($selectedClass)
                <button wire:click="clearSelectedClass" class="text-sm text-blue-600 hover:underline">
                    Clear filter ({{ $selectedClass }})
                </button>
            @endif
        </div>
        @if (count($classes) === 0)
            <div class="rounded border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                No processed jobs in the window.
            </div>
        @else
            <div class="overflow-hidden rounded border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Class</th>
                            <th class="px-3 py-2">Processed</th>
                            <th class="px-3 py-2">Failed</th>
                            <th class="px-3 py-2">Avg ms</th>
                            <th class="px-3 py-2">p95 ms</th>
                            <th class="px-3 py-2">Max ms</th>
                            <th class="px-3 py-2">Last run</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($classes as $c)
                            <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50" wire:click="selectClass('{{ $c['class'] }}')">
                                <td class="px-3 py-2 font-mono text-xs">{{ $c['class'] }}</td>
                                <td class="px-3 py-2">{{ $c['processed_24h'] }}</td>
                                <td class="px-3 py-2 {{ $c['failed_24h'] > 0 ? 'text-red-600' : '' }}">{{ $c['failed_24h'] }}</td>
                                <td class="px-3 py-2">{{ $c['avg_ms'] !== null ? number_format($c['avg_ms'], 1) : '—' }}</td>
                                <td class="px-3 py-2">{{ $c['p95_ms'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $c['max_ms'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $c['last_run_at']?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Recent completed --}}
    <section>
        <h2 class="mb-3 text-lg font-semibold">
            Recent completed @if ($selectedClass)<span class="text-sm text-gray-500">({{ $selectedClass }})</span>@endif
        </h2>
        @if (! $captureEnabled && count($recentCompleted) === 0)
            <div class="rounded border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                Payload capture is off by default — set <code>QUEUE_INSIGHTS_CAPTURE_PAYLOADS=full</code> and configure a sanitizer to see job bodies.
            </div>
        @elseif (count($recentCompleted) === 0)
            <div class="rounded border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                No completed jobs recorded yet.
            </div>
        @else
            <div class="overflow-hidden rounded border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Class</th>
                            <th class="px-3 py-2">Conn</th>
                            <th class="px-3 py-2">Queue</th>
                            <th class="px-3 py-2">Duration</th>
                            <th class="px-3 py-2">Attempts</th>
                            <th class="px-3 py-2">At</th>
                            @if ($captureEnabled)
                                <th class="px-3 py-2">Payload</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentCompleted as $row)
                            <tr class="border-t border-gray-100">
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['class'] ?? $selectedClass }}</td>
                                <td class="px-3 py-2">{{ $row['connection'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['queue'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['duration_ms'] ?? '—' }} ms</td>
                                <td class="px-3 py-2">{{ $row['attempts'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $row['processed_at'] ?? '—' }}</td>
                                @if ($captureEnabled)
                                    <td class="px-3 py-2">
                                        <button wire:click="openPayload('{{ $row['_id'] }}')" class="text-xs text-blue-600 hover:underline">view</button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Recent failed --}}
    <section>
        <h2 class="mb-3 text-lg font-semibold">Recent failed</h2>
        @if (count($recentFailed) === 0)
            <div class="rounded border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                No failed jobs recorded.
            </div>
        @else
            <div class="overflow-hidden rounded border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">UUID</th>
                            <th class="px-3 py-2">Conn</th>
                            <th class="px-3 py-2">Queue</th>
                            <th class="px-3 py-2">Failed at</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentFailed as $f)
                            <tr class="border-t border-gray-100">
                                <td class="px-3 py-2 font-mono text-xs">{{ $f['uuid'] ?? $f['id'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $f['connection'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $f['queue'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $f['failed_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Payload modal --}}
    @if ($selectedPayload !== null)
        <div class="fixed inset-0 flex items-center justify-center bg-black/40 p-4" wire:click="closePayload">
            <div class="max-h-[80vh] w-full max-w-2xl overflow-auto rounded bg-white p-5 shadow-lg" wire:click.stop>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold">Payload</h3>
                    <button wire:click="closePayload" class="text-sm text-gray-500 hover:underline">close</button>
                </div>
                <pre class="whitespace-pre-wrap break-all rounded bg-gray-50 p-3 text-xs">{{ json_encode($selectedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif
</div>
@endsection
