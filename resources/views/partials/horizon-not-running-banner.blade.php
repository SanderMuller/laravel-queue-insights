@php
    /**
     * Amber warning banner — Horizon's service provider is loaded (so this app
     * IS meant to run Horizon), supervisors are configured for the current
     * env, but no master is heartbeating. The "you dispatched jobs but forgot
     * to start Horizon" state. Clears within ~14s of `php artisan horizon`
     * starting, since Horizon's `MasterSupervisorRepository->all()` already
     * returns only masters seen inside its own 14-second liveness window.
     *
     * The helper is resolved inline rather than threaded through
     * `DashboardData::build()` view-scope vars: the banner is a self-contained
     * dashboard surface and the helper is stateless (one provider scan + one
     * `MasterSupervisorRepository->all()` call), so scope-var plumbing would
     * be overkill for a single visibility flag. The blade still accepts an
     * explicit `$horizonNotRunning` override when rendered in isolation (the
     * test suite passes it directly), keeping the partial unit-renderable.
     */
    $horizonNotRunning ??= app(\SanderMuller\QueueInsights\Support\HorizonNotRunning::class)->isNotRunning();
@endphp
@if($horizonNotRunning)
    <div role="alert" class="flex items-start gap-3 rounded-lg bg-amber-100 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-700/30 dark:bg-amber-900/40 dark:text-amber-200 dark:ring-amber-400/30">
        <svg class="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.485 3.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.169 2.625-1.515 2.625H3.72c-1.346 0-2.188-1.458-1.515-2.625l6.28-10.875ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
        </svg>
        <div class="min-w-0 flex-1">
            <p class="font-semibold">Horizon is configured but no master is running.</p>
            <p class="mt-0.5 text-xs opacity-90">
                Horizon's service provider is loaded for this environment and supervisors are defined in
                <code class="rounded bg-amber-200/60 px-1 py-0.5 font-mono text-[11px] dark:bg-amber-400/20">config/horizon.php</code>,
                but no master supervisor is heartbeating. Dispatched jobs aren't being processed until you start
                <code class="rounded bg-amber-200/60 px-1 py-0.5 font-mono text-[11px] dark:bg-amber-400/20">php artisan horizon</code>.
                Clears within ~14 seconds of Horizon coming back up.
            </p>
        </div>
    </div>
@endif
