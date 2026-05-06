<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\Prometheus\ClassFilter;

/**
 * Prometheus-block validator. Extracted from `ConfigValidator` to keep
 * that class under PHPStan's cognitive-complexity ceiling — six top-
 * level keys plus the nested `class_filter` and `metrics` blocks were
 * pushing the parent class above the 80-class / 20-method limits.
 *
 * @internal
 */
final class PrometheusConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $prometheus
     */
    public static function validate(array $prometheus): void
    {
        self::validateBoolean($prometheus, 'enabled');
        self::validatePath($prometheus);
        self::validateMiddleware($prometheus);
        self::validateToken($prometheus);
        self::validateAllowIps($prometheus);
        self::validateClassFilter($prometheus);
        self::validateMetrics($prometheus);
        self::validateCacheTtl($prometheus);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateBoolean(array $config, string $key): void
    {
        if (isset($config[$key]) && ! is_bool($config[$key])) {
            throw new QueueInsightsConfigException(
                "queue-insights.prometheus.{$key} must be a boolean."
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validatePath(array $config): void
    {
        if (! isset($config['path'])) {
            return;
        }

        $path = $config['path'];
        if (! is_string($path) || $path === '') {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.path must be a non-empty string.'
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateMiddleware(array $config): void
    {
        if (! array_key_exists('middleware', $config) || $config['middleware'] === null) {
            return;
        }

        $middleware = $config['middleware'];
        if (! is_array($middleware)) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.middleware must be null or an array of middleware names.'
            );
        }

        foreach ($middleware as $i => $entry) {
            if (! is_string($entry) || $entry === '') {
                throw new QueueInsightsConfigException(
                    "queue-insights.prometheus.middleware[{$i}] must be a non-empty string."
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateToken(array $config): void
    {
        if (! array_key_exists('token', $config) || $config['token'] === null) {
            return;
        }

        if (! is_string($config['token'])) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.token must be a string or null.'
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateAllowIps(array $config): void
    {
        if (! isset($config['allow_ips'])) {
            return;
        }

        $allowIps = $config['allow_ips'];
        if (! is_array($allowIps)) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.allow_ips must be a list of CIDR strings.'
            );
        }

        foreach ($allowIps as $i => $cidr) {
            self::assertValidCidr($cidr, (string) $i);
        }
    }

    private static function assertValidCidr(mixed $cidr, string $position): void
    {
        if (! is_string($cidr) || $cidr === '') {
            throw new QueueInsightsConfigException(
                "queue-insights.prometheus.allow_ips[{$position}] must be a non-empty string."
            );
        }

        // Reject obviously-malformed entries before they reach
        // `IpUtils::checkIp`, which silently returns false on bad
        // input (operators wouldn't notice the allow-list never
        // matches).
        $candidate = str_contains($cidr, '/') ? explode('/', $cidr, 2)[0] : $cidr;
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            throw new QueueInsightsConfigException(
                "queue-insights.prometheus.allow_ips[{$position}] is not a valid IP or CIDR (`{$cidr}`)."
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateClassFilter(array $config): void
    {
        if (! isset($config['class_filter'])) {
            return;
        }

        $filter = $config['class_filter'];
        if (! is_array($filter)) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.class_filter must be an array.'
            );
        }

        self::validateClassFilterMode($filter);
        self::validateClassFilterClasses($filter);
        self::validateClassFilterTopN($filter);
    }

    /**
     * @param  array<array-key, mixed>  $filter
     */
    private static function validateClassFilterMode(array $filter): void
    {
        if (! isset($filter['mode'])) {
            return;
        }

        $mode = $filter['mode'];
        $allowed = [
            ClassFilter::MODE_ALLOW_ALL,
            ClassFilter::MODE_ALLOW_LIST,
            ClassFilter::MODE_TOP_N_BY_RECENCY,
        ];
        if (! is_string($mode) || ! in_array($mode, $allowed, true)) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.class_filter.mode must be one of: '
                . implode(', ', $allowed) . '.'
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $filter
     */
    private static function validateClassFilterClasses(array $filter): void
    {
        if (! isset($filter['classes'])) {
            return;
        }

        $classes = $filter['classes'];
        if (! is_array($classes)) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.class_filter.classes must be a list of FQCN strings.'
            );
        }

        foreach ($classes as $i => $entry) {
            if (! is_string($entry) || $entry === '') {
                throw new QueueInsightsConfigException(
                    "queue-insights.prometheus.class_filter.classes[{$i}] must be a non-empty string."
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $filter
     */
    private static function validateClassFilterTopN(array $filter): void
    {
        if (! isset($filter['top_n'])) {
            return;
        }

        $topN = $filter['top_n'];
        if (! is_int($topN) || $topN < 1) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.class_filter.top_n must be a positive integer.'
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateMetrics(array $config): void
    {
        if (! isset($config['metrics'])) {
            return;
        }

        $metrics = $config['metrics'];
        if (! is_array($metrics)) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.metrics must be a map of metric name => bool.'
            );
        }

        foreach ($metrics as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new QueueInsightsConfigException(
                    'queue-insights.prometheus.metrics keys must be non-empty strings.'
                );
            }

            if (! is_bool($value)) {
                throw new QueueInsightsConfigException(
                    "queue-insights.prometheus.metrics.{$key} must be a boolean."
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function validateCacheTtl(array $config): void
    {
        if (! array_key_exists('cache_ttl_seconds', $config)) {
            return;
        }

        $ttl = $config['cache_ttl_seconds'];
        if (! is_int($ttl) || $ttl < 0) {
            throw new QueueInsightsConfigException(
                'queue-insights.prometheus.cache_ttl_seconds must be a non-negative integer (0 disables the cache).'
            );
        }
    }
}
