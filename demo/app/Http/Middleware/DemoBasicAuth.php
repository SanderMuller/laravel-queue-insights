<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic-auth gate for the public Laravel Cloud demo. Only purpose is to
 * keep search engines + casual scrapers off the dashboard URL — the
 * seeded fixtures use realistic class names and we don't want them
 * indexed as if they were real prod data.
 *
 * No-ops locally so `php artisan serve` doesn't prompt during dev. Set
 * DEMO_BASIC_USER + DEMO_BASIC_PASS in the Cloud env to activate.
 */
final readonly class DemoBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Read via config() so the values survive `php artisan config:cache`
        // (Cloud's deploy command). env() in middleware returns null after
        // config caching, which would silently disable the gate.
        $expectedUser = (string) config('demo.basic_auth.user', '');
        $expectedPass = (string) config('demo.basic_auth.password', '');

        $userSet = $expectedUser !== '';
        $passSet = $expectedPass !== '';

        // Both blank: gate disabled (local dev default).
        if (! $userSet && ! $passSet) {
            return $next($request);
        }

        // Exactly one set: fail CLOSED. A typo or partial Cloud env config
        // would otherwise silently re-open the demo. 503 communicates the
        // misconfig clearly without revealing which half was missing.
        if ($userSet xor $passSet) {
            return response('Demo basic-auth is misconfigured (set both DEMO_BASIC_USER and DEMO_BASIC_PASS, or neither).', 503);
        }

        $authUser = $request->getUser() ?? '';
        $authPass = $request->getPassword() ?? '';

        $userOk = hash_equals($expectedUser, $authUser);
        $passOk = hash_equals($expectedPass, $authPass);

        if (! $userOk || ! $passOk) {
            return response('Authentication required', 401, [
                'WWW-Authenticate' => 'Basic realm="Queue Insights Demo"',
            ]);
        }

        return $next($request);
    }
}
