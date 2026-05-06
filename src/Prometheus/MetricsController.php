<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MetricsController
{
    public function __invoke(Request $request, Registry $registry): Response
    {
        $accept = (string) $request->header('Accept', '');
        $openmetrics = str_contains($accept, 'application/openmetrics-text');

        $body = $registry->render($openmetrics);

        return new Response(
            $body,
            Response::HTTP_OK,
            [
                'Content-Type' => $openmetrics
                    ? Renderer::CONTENT_TYPE_OPENMETRICS
                    : Renderer::CONTENT_TYPE_TEXT,
            ],
        );
    }
}
