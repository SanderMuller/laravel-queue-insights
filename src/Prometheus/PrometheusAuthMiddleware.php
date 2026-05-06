<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

use Closure;
use Illuminate\Http\Request;
use SanderMuller\QueueInsights\Support\Config;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default `/metrics` gate. Order:
 *
 *   1. `prometheus.token` set → require `Authorization: Bearer <token>`,
 *      constant-time comparison.
 *   2. `prometheus.allow_ips` non-empty → CIDR-match `$request->ip()`.
 *   3. Neither configured → 403, no silent open default.
 *
 * Hosts overriding `prometheus.middleware` opt out of these defaults
 * entirely; they're responsible for their own gate.
 */
final class PrometheusAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = Config::string('prometheus.token', '');
        if ($token !== '') {
            $authHeaderRaw = $request->header('Authorization', '');
            $authHeader = is_string($authHeaderRaw) ? $authHeaderRaw : '';
            if (! hash_equals('Bearer ' . $token, $authHeader)) {
                return $this->forbidden('Invalid bearer token.');
            }

            return $this->coerceResponse($next($request));
        }

        $allowIps = array_values(array_filter(
            Config::array('prometheus.allow_ips'),
            is_string(...),
        ));
        if ($allowIps !== []) {
            $ip = $request->ip() ?? '';
            if (! IpUtils::checkIp($ip, $allowIps)) {
                return $this->forbidden('Source IP not in allow-list.');
            }

            return $this->coerceResponse($next($request));
        }

        return $this->forbidden(
            'Configure queue-insights.prometheus.token or queue-insights.prometheus.allow_ips to expose /metrics.'
        );
    }

    private function coerceResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        $body = is_string($response) ? $response : '';

        return new Response($body);
    }

    private function forbidden(string $message): Response
    {
        return new Response($message, Response::HTTP_FORBIDDEN, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
