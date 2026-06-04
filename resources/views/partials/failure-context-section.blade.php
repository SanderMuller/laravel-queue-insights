@php
    /**
     * Shared failure-context surface (Context + Environment) for the failed-job
     * and scheduled-run modals. Renders nothing when both are empty.
     *
     * @var array<array-key, mixed> $appContext
     * @var array<array-key, mixed> $environment
     */
    $appContext = $appContext ?? [];
    $environment = $environment ?? [];

    $envPairs = [];
    foreach (['host', 'pid', 'env', 'release'] as $envKey) {
        $envVal = $environment[$envKey] ?? null;
        if ($envVal !== null && $envVal !== '') {
            $envPairs[$envKey] = $envVal;
        }
    }
@endphp

@if($envPairs !== [])
    <section data-section="failure-environment">
        <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Environment</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-xs ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
            @foreach($envPairs as $envKey => $envVal)
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ ucfirst($envKey) }}</dt>
                    <dd class="min-w-0 truncate text-right font-medium text-gray-900 dark:text-gray-100">{{ $envVal }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif

@if($appContext !== [])
    <section data-section="failure-context">
        <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Context</p>
        <dl class="space-y-1 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-xs ring-1 ring-inset ring-gray-950/5 dark:ring-white/10">
            @foreach($appContext as $ctxKey => $ctxVal)
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="shrink-0 font-mono text-gray-500 dark:text-gray-400">{{ $ctxKey }}</dt>
                    <dd class="min-w-0 truncate text-right font-medium text-gray-900 dark:text-gray-100">{{ is_scalar($ctxVal) ? (string) $ctxVal : json_encode($ctxVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif
