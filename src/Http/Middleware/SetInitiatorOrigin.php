<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Support\Config;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stamps the coarse HTTP origin onto hidden Laravel `Context` so every job
 * dispatched during the request carries `http:{route}` into its payload.
 *
 * Appended to the `web` / `api` middleware groups by
 * `QueueInsightsServiceProvider::boot()` when `initiator.enabled` is true.
 * Group middleware runs *after* route matching, so `$request->route()` is
 * populated by the time `handle()` runs.
 *
 * Origin precedence (spec Resolved-Q2): route name → `Controller@method`
 * action → `{HTTP_METHOD} {uri}` for bare closure routes.
 *
 * Coverage is partial by construction (spec §3.1): custom route groups,
 * route-less stacks, and dispatches before the group middleware get no
 * HTTP origin — they fall through to *absent*.
 */
final class SetInitiatorOrigin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (Config::bool('initiator.enabled', true) && Config::bool('initiator.capture_origin', true)) {
                $origin = $this->resolveOrigin($request);
                if ($origin !== null) {
                    Context::addHidden(
                        Config::string('initiator.context_key', 'qi_origin'),
                        'http:' . $origin,
                    );
                }
            }
        } catch (Throwable $throwable) {
            // Origin capture is best-effort — never break a request over it.
            Log::warning('queue-insights: SetInitiatorOrigin failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        return $next($request);
    }

    /**
     * Precedence (spec Resolved-Q2): route name → controller action →
     * `{HTTP_METHOD} {uri}`. The method+URI fallback covers bare closure
     * routes (no controller, no name) so even those get a coarse origin
     * (`http:GET orders/{order}`) rather than falling through to absent.
     * Returns null only when there is no matched route at all.
     */
    private function resolveOrigin(Request $request): ?string
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        $name = $route->getName();
        if (is_string($name) && $name !== '') {
            return $name;
        }

        $action = $route->getActionName();
        if (is_string($action) && $action !== '' && $action !== 'Closure') {
            return $action;
        }

        // Bare closure route — no name, no controller. Fall back to the
        // HTTP method + the route's URI pattern (path-parameter placeholders
        // kept, so the origin stays stable across requests).
        return $request->getMethod() . ' ' . $route->uri();
    }
}
