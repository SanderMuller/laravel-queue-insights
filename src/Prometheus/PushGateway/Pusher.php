<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus\PushGateway;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Posts a rendered Prometheus exposition body to a Pushgateway. PUT
 * replaces the previous group's metrics; DELETE clears it. Grouping
 * key is `/job/{job}` plus optionally `/instance/{instance}`.
 *
 * Basic auth in the URL (`https://user:pass@host/`) is forwarded via
 * `withBasicAuth`. The host portion of the URL is URL-decoded for
 * label values per Pushgateway's escaping rules — see
 * https://github.com/prometheus/pushgateway#url-encoding-of-grouping-key
 *
 * @internal
 */
final readonly class Pusher
{
    public function __construct(
        private HttpFactory $http,
    ) {}

    public function push(
        string $baseUrl,
        string $job,
        ?string $instance,
        string $body,
        string $contentType,
    ): void {
        $this->dispatch($baseUrl, $job, $instance, 'PUT', $body, $contentType);
    }

    public function delete(
        string $baseUrl,
        string $job,
        ?string $instance,
    ): void {
        $this->dispatch($baseUrl, $job, $instance, 'DELETE', '', '');
    }

    private function dispatch(
        string $baseUrl,
        string $job,
        ?string $instance,
        string $method,
        string $body,
        string $contentType,
    ): void {
        if ($job === '') {
            throw new InvalidArgumentException('Pushgateway job label is empty.');
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Pushgateway URL [{$baseUrl}] is not a valid absolute URL.");
        }

        $request = $this->http->createPendingRequest();
        if (isset($parts['user'], $parts['pass'])) {
            $request = $request->withBasicAuth($parts['user'], $parts['pass']);
        }

        $url = $this->buildUrl($parts, $job, $instance);

        $response = $method === 'PUT' ? $this->put($request, $url, $body, $contentType) : $request->delete($url);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Pushgateway %s %s failed with status %d: %s',
                $method,
                $url,
                $response->status(),
                $response->body(),
            ));
        }
    }

    private function put(PendingRequest $request, string $url, string $body, string $contentType): Response
    {
        return $request
            ->withHeaders(['Content-Type' => $contentType])
            ->withBody($body, $contentType)
            ->put($url);
    }

    /**
     * @param  array<string, string|int>  $parts
     */
    private function buildUrl(array $parts, string $job, ?string $instance): string
    {
        $scheme = (string) $parts['scheme'];
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        if ($path === '' || ! str_ends_with($path, '/metrics')) {
            $path .= '/metrics';
        }

        $url = "{$scheme}://{$host}{$port}{$path}/job/" . rawurlencode($job);
        if ($instance !== null && $instance !== '') {
            $url .= '/instance/' . rawurlencode($instance);
        }

        return $url;
    }
}
