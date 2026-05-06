<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SanderMuller\QueueInsights\Prometheus\PushGateway\Pusher;
use SanderMuller\QueueInsights\Prometheus\Registry;
use SanderMuller\QueueInsights\Prometheus\Renderer;
use SanderMuller\QueueInsights\Support\Config;
use Throwable;

/**
 * One-shot Prometheus push for short-lived processes (CLI scripts,
 * scheduled tasks) that exit before any scrape lands. Long-running
 * workers should be scraped, not pushed — see README.
 *
 * Fail-closed on `pushgateway.instance`: clustered hosts that share
 * a `job` label without distinct `instance` values silently overwrite
 * each other's pushed metrics. Operators must either set
 * `pushgateway.instance` explicitly OR pass `--accept-shared-grouping`
 * to acknowledge the risk.
 */
final class QueueInsightsPrometheusPushCommand extends Command
{
    protected $signature = 'queue-insights:prometheus-push
                            {--delete : Clear the grouping at the Pushgateway instead of pushing metrics}
                            {--accept-shared-grouping : Acknowledge that pushgateway.instance is unset and replicas may overwrite each other}';

    protected $description = 'Push the queue-insights Prometheus exposition body to a configured Pushgateway.';

    public function __construct(
        private readonly Pusher $pusher,
        private readonly Registry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = Config::string('prometheus.pushgateway.url', '');
        if ($url === '') {
            $this->error('queue-insights.prometheus.pushgateway.url is not set.');

            return self::INVALID;
        }

        $job = Config::string('prometheus.pushgateway.job', 'laravel-queue-insights');
        $instance = Config::string('prometheus.pushgateway.instance', '');
        if ($instance === '') {
            $instance = null;
        }

        if ($instance === null && $this->option('accept-shared-grouping') !== true) {
            $this->error(
                'queue-insights.prometheus.pushgateway.instance is unset. '
                . "Set it (typically env('HOSTNAME') or a per-replica identifier) "
                . 'or re-run with --accept-shared-grouping to override.'
            );

            return self::INVALID;
        }

        try {
            if ($this->option('delete') === true) {
                $this->pusher->delete($url, $job, $instance);
                $this->info(sprintf('Cleared Pushgateway grouping job=%s instance=%s', $job, $instance ?? '(none)'));

                return self::SUCCESS;
            }

            $body = $this->registry->render();
            $this->pusher->push($url, $job, $instance, $body, Renderer::CONTENT_TYPE_TEXT);
            $this->info(sprintf('Pushed %d bytes to Pushgateway job=%s instance=%s', strlen($body), $job, $instance ?? '(none)'));
        } catch (InvalidArgumentException $configException) {
            // Malformed `pushgateway.url` etc. — config-shape problem, not
            // a transient downstream failure. Surfaces with INVALID (2)
            // so CI / shell pipelines can distinguish "deploy is
            // misconfigured" from "pushgateway is having a bad day".
            $this->error('Pushgateway config error: ' . $configException->getMessage());

            return self::INVALID;
        } catch (Throwable $throwable) {
            $this->error('Pushgateway request failed: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
