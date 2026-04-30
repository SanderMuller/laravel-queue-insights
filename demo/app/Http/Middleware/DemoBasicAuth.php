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
        $expectedUser = (string) env('DEMO_BASIC_USER', '');
        $expectedPass = (string) env('DEMO_BASIC_PASS', '');

        if ($expectedUser === '' || $expectedPass === '') {
            return $next($request);
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
