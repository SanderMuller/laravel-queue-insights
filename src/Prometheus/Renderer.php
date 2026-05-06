<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Prometheus;

use InvalidArgumentException;
use SanderMuller\QueueInsights\Prometheus\Exposition\EscapeLabel;
use SanderMuller\QueueInsights\Prometheus\Exposition\MetricFamily;
use SanderMuller\QueueInsights\Prometheus\Exposition\Sample;

/**
 * Renders {@see MetricFamily} lists into Prometheus exposition text.
 * Two flavours:
 *
 *   - 0.0.4 plain text (default content-type)
 *   - OpenMetrics 1.0.0 (when the scraper sends
 *     `Accept: application/openmetrics-text`)
 *
 * The OpenMetrics flavour adds a trailing `# EOF\n` sentinel; otherwise
 * the body is byte-identical between flavours. Sample ordering: families
 * are emitted in registration order; labels within a sample are sorted
 * alphabetically so output is deterministic across runs.
 *
 * @internal
 */
final class Renderer
{
    public const string CONTENT_TYPE_TEXT = 'text/plain; version=0.0.4; charset=utf-8';

    public const string CONTENT_TYPE_OPENMETRICS = 'application/openmetrics-text; version=1.0.0; charset=utf-8';

    /**
     * @param  list<MetricFamily>  $families
     */
    public function render(array $families, bool $openmetrics = false): string
    {
        $out = '';

        foreach ($families as $family) {
            if (! EscapeLabel::isValidMetricName($family->name)) {
                throw new InvalidArgumentException("Invalid metric name [{$family->name}].");
            }

            $out .= "# HELP {$family->name} " . $this->escapeHelp($family->help) . "\n";
            $out .= "# TYPE {$family->name} {$family->type}\n";

            foreach ($family->samples as $sample) {
                $out .= $this->renderSample($sample);
            }
        }

        if ($openmetrics) {
            $out .= "# EOF\n";
        }

        return $out;
    }

    private function renderSample(Sample $sample): string
    {
        if (! EscapeLabel::isValidMetricName($sample->name)) {
            throw new InvalidArgumentException("Invalid sample name [{$sample->name}].");
        }

        $labels = $sample->labels;
        ksort($labels);

        $rendered = '';
        if ($labels !== []) {
            $parts = [];
            foreach ($labels as $key => $value) {
                $parts[] = $key . '="' . EscapeLabel::value($value) . '"';
            }

            $rendered = '{' . implode(',', $parts) . '}';
        }

        return $sample->name . $rendered . ' ' . $this->formatValue($sample->value) . "\n";
    }

    private function formatValue(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        // Integral floats render as integers — matches Prometheus's own
        // exposition output for counter/gauge values that are whole
        // numbers and avoids meaningless trailing zeros.
        if ($value === (float) (int) $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        $formatted = sprintf('%.6f', $value);
        $formatted = rtrim($formatted, '0');

        return rtrim($formatted, '.');
    }

    /**
     * HELP text escaping per Prometheus text-format spec — backslash + LF
     * are the only required escapes.
     */
    private function escapeHelp(string $help): string
    {
        return strtr($help, [
            '\\' => '\\\\',
            "\n" => '\\n',
        ]);
    }
}
