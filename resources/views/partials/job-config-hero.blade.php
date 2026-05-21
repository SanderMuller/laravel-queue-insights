{{--
    Job-Config hero card — gradient surface carrying the job's configured
    retry/timeout policy as label/value pills plus the payload `tags` array
    as chips. Shared by the completed-jobs details modal and the failed-jobs
    modal so both render job config identically.

    Self-gating: renders nothing when there are no pills, no tags, and no
    subtitle — callers can `@include` it unconditionally.

    Vars:
      $body     — decoded Laravel queue payload (array) or null/string
      $subtitle — optional string shown under the "Job Config" label. The
                  details modal passes the displayName when it diverges from
                  the class FQCN (a job that overrode `displayName()` with
                  subject context); the failed modal passes null since its
                  title already IS the displayName.
--}}
@php
    $jobConfigKeys = ['maxTries', 'maxExceptions', 'timeout', 'backoff', 'retryUntil', 'failOnTimeout'];
    $body = $body ?? null;
    $subtitle = $subtitle ?? null;

    $configPills = [];
    if (is_array($body)) {
        foreach ($jobConfigKeys as $configKey) {
            $configVal = $body[$configKey] ?? null;
            if ($configVal === null || $configVal === '') {
                continue;
            }
            if ($configKey === 'backoff' && is_array($configVal)) {
                $configPills[$configKey] = implode(', ', array_map(fn ($v): string => (string) $v, $configVal)) . ' s';
            } elseif ($configKey === 'timeout' && is_numeric($configVal)) {
                $configPills[$configKey] = ((int) $configVal) . ' s';
            } elseif (is_bool($configVal)) {
                $configPills[$configKey] = $configVal ? 'true' : 'false';
            } elseif (is_scalar($configVal)) {
                $configPills[$configKey] = (string) $configVal;
            }
        }
    }

    $jobTags = is_array($body) && is_array($body['tags'] ?? null)
        ? array_values(array_filter(
            $body['tags'],
            fn (mixed $t): bool => is_scalar($t) && (string) $t !== '',
        ))
        : [];
@endphp
@if($configPills !== [] || $jobTags !== [] || ($subtitle !== null && $subtitle !== ''))
    <div class="rounded-xl bg-gradient-to-br from-gray-50 to-white p-4 ring-1 ring-inset ring-gray-950/10 dark:from-gray-800 dark:to-gray-900 dark:ring-white/10">
        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Job Config</p>

        @if($subtitle !== null && $subtitle !== '')
            <p class="mt-1 break-all font-mono text-sm text-gray-700 dark:text-gray-300">{{ $subtitle }}</p>
        @endif

        @if($configPills !== [])
            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                @foreach($configPills as $pillKey => $pillVal)
                    <span class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-0.5 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                        <span class="text-gray-500 dark:text-gray-400">{{ $pillKey }}</span>
                        <span class="font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $pillVal }}</span>
                    </span>
                @endforeach
            </div>
        @endif

        @if($jobTags !== [])
            <div class="mt-2 flex flex-wrap items-center gap-1 text-[11px]">
                <span class="text-gray-500 dark:text-gray-400">tags</span>
                @foreach($jobTags as $tag)
                    <span class="rounded bg-white px-1.5 py-0.5 font-mono text-[10px] text-gray-700 ring-1 ring-inset ring-gray-950/10 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10">{{ (string) $tag }}</span>
                @endforeach
            </div>
        @endif
    </div>
@endif
