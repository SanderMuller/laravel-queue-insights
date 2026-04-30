{{-- Renders a PHP exception string as a Horizon-style framed list:
    header line on top (red), then each `#N file(line): call` frame as its own row
    with file:line in muted mono on top + the call on the next line.
    Vendor frames (paths under /vendor/ or [internal function]) are visually de-emphasized.

    Props:
      $exception — string | null  (raw exception output, typically Throwable->__toString())
--}}
@props(['exception' => null])

@php
    $raw = is_string($exception) ? trim($exception) : '';
@endphp

@if($raw !== '')
    @php
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $header = $lines[0] ?? '';
        $frames = [];
        foreach (array_slice($lines, 1) as $line) {
            if ($line === '') {
                continue;
            }

            // `#N <location>: <call>` is the canonical Throwable trace format.
            if (preg_match('/^#(\d+)\s+(.+?)(?::\s+(.*))?$/', $line, $m) === 1) {
                $location = $m[2] ?? '';
                $call = $m[3] ?? '';
                $isVendor = str_contains($location, '/vendor/') || $location === '[internal function]';
                $isMain = trim($location) === '{main}';

                // Split file path + line number for separate rendering.
                $file = $location;
                $lineNum = null;
                if (preg_match('/^(.+)\((\d+)\)$/', $location, $fm) === 1) {
                    $file = $fm[1];
                    $lineNum = (int) $fm[2];
                }

                $frames[] = [
                    'index' => (int) $m[1],
                    'file' => $file,
                    'line' => $lineNum,
                    'call' => $call,
                    'is_vendor' => $isVendor,
                    'is_main' => $isMain,
                ];
            } else {
                // Non-standard line — keep as a "raw" frame so we don't drop it.
                $frames[] = [
                    'index' => null,
                    'file' => $line,
                    'line' => null,
                    'call' => '',
                    'is_vendor' => false,
                    'is_main' => false,
                ];
            }
        }

        // Split header into class + message for visual hierarchy.
        $headerClass = $header;
        $headerMessage = '';
        if (($colon = strpos($header, ':')) !== false) {
            $headerClass = trim(substr($header, 0, $colon));
            $headerMessage = trim(substr($header, $colon + 1));
        }

        $appFrameCount = count(array_filter($frames, fn (array $f): bool => ! $f['is_vendor'] && ! $f['is_main']));
        $vendorFrameCount = count(array_filter($frames, fn (array $f): bool => $f['is_vendor']));
    @endphp
    <div class="overflow-hidden rounded-lg ring-1 ring-gray-950/10" x-data="{ showVendor: {{ $appFrameCount === 0 ? 'true' : 'false' }} }">
        {{-- Header: exception class + message --}}
        <div class="border-b border-red-600/20 bg-red-50 px-4 py-3 text-sm">
            <p class="break-all font-mono text-xs font-medium text-red-700">{{ $headerClass }}</p>
            @if($headerMessage !== '')
                <p class="mt-1 break-words font-mono text-sm text-red-900">{{ $headerMessage }}</p>
            @endif
        </div>

        {{-- Frames --}}
        @if(count($frames) === 0)
            <p class="px-4 py-3 text-xs text-gray-500">No stack frames available.</p>
        @else
            <ol role="list" class="divide-y divide-gray-950/5 bg-white">
                @foreach($frames as $f)
                    <li class="px-4 py-2 text-xs"
                        @if($f['is_vendor']) x-show="showVendor" x-cloak @endif>
                        <div class="flex items-baseline gap-3">
                            @if($f['index'] !== null)
                                <span class="shrink-0 font-mono text-[10px] tabular-nums {{ $f['is_vendor'] ? 'text-gray-300' : 'text-gray-400' }}">#{{ $f['index'] }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                @if($f['is_main'])
                                    <p class="font-mono text-[11px] italic {{ $f['is_vendor'] ? 'text-gray-400' : 'text-gray-600' }}">{main}</p>
                                @else
                                    <p class="break-all font-mono text-[11px] {{ $f['is_vendor'] ? 'text-gray-400' : 'text-gray-700' }}">
                                        <span>{{ $f['file'] }}</span>
                                        @if($f['line'] !== null)
                                            <span class="ml-1 tabular-nums {{ $f['is_vendor'] ? 'text-gray-300' : 'text-emerald-700' }}">:{{ $f['line'] }}</span>
                                        @endif
                                    </p>
                                    @if($f['call'] !== '')
                                        <p class="mt-0.5 break-all font-mono text-[11px] {{ $f['is_vendor'] ? 'text-gray-400' : 'text-gray-900' }}">{{ $f['call'] }}</p>
                                    @endif
                                @endif
                            </div>
                            @if($f['is_vendor'])
                                <span class="shrink-0 rounded bg-gray-950/5 px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wider text-gray-400">vendor</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            {{-- Show vendor toggle (only when both classes of frames exist) --}}
            @if($vendorFrameCount > 0 && $appFrameCount > 0)
                <div class="border-t border-gray-950/5 bg-gray-50/50 px-4 py-2 text-right">
                    <button type="button"
                            @click="showVendor = ! showVendor"
                            class="rounded text-[11px] font-medium text-emerald-700 hover:text-emerald-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        <span x-show="! showVendor">Show {{ $vendorFrameCount }} vendor frame{{ $vendorFrameCount === 1 ? '' : 's' }}</span>
                        <span x-show="showVendor" x-cloak>Hide vendor frames</span>
                    </button>
                </div>
            @endif
        @endif
    </div>
@endif
