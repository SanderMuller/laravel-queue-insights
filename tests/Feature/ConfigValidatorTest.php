<?php declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use SanderMuller\QueueInsights\Exceptions\QueueInsightsConfigException;
use SanderMuller\QueueInsights\QueueInsightsServiceProvider;
use SanderMuller\QueueInsights\Support\ConfigValidator;

it('accepts a non-colliding snapshot list', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'default'],
            ['connection' => 'sqs', 'queue' => 'high'],
            ['connection' => 'redis', 'queue' => 'default'],
        ]);
    })->not->toThrow(Throwable::class);
});

it('does not collide when the same canonical key appears on different connections', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'default'],
            ['connection' => 'database', 'queue' => 'default'],
        ]);
    })->not->toThrow(Throwable::class);
});

it('treats SQS URL and bare queue name as the same canonical entry', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'https://sqs.eu-west-1.amazonaws.com/123/my-q'],
            ['connection' => 'sqs', 'queue' => 'my-q'],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'collision');
});

it('throws when two different inputs normalize to the same canonical key', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => 'foo/bar'],
            ['connection' => 'sqs', 'queue' => 'foo_bar'],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'foo_bar');
});

it('rejects empty queue strings at boot', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => 'sqs', 'queue' => ''],
        ]);
    })->toThrow(QueueInsightsConfigException::class);
});

it('rejects malformed entries (missing keys)', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['queue' => 'default'],
        ]);
    })->toThrow(QueueInsightsConfigException::class)
        ->and(function (): void {
            ConfigValidator::validateSnapshots([
                ['connection' => 'sqs'],
            ]);
        })->toThrow(QueueInsightsConfigException::class);
});

it('rejects empty connection strings', function (): void {
    expect(function (): void {
        ConfigValidator::validateSnapshots([
            ['connection' => '', 'queue' => 'default'],
        ]);
    })->toThrow(QueueInsightsConfigException::class, 'empty connection');
});

it('accepts a well-formed pending block (or empty defaults)', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending([]);
        ConfigValidator::validatePending([
            'enabled' => true,
            'max_per_queue' => 5000,
            'ttl_seconds' => 3600,
            'gap_warn_threshold' => 10,
        ]);
    })->not->toThrow(Throwable::class);
});

it('rejects a non-boolean pending.enabled', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['enabled' => 'yes']);
    })->toThrow(QueueInsightsConfigException::class, 'pending.enabled must be a boolean');
});

it('rejects a non-int max_per_queue', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['max_per_queue' => '10000']);
    })->toThrow(QueueInsightsConfigException::class, 'pending.max_per_queue must be a positive integer');
});

it('rejects a zero max_per_queue', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['max_per_queue' => 0]);
    })->toThrow(QueueInsightsConfigException::class, 'pending.max_per_queue must be a positive integer');
});

it('rejects a negative ttl_seconds', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['ttl_seconds' => -1]);
    })->toThrow(QueueInsightsConfigException::class, 'pending.ttl_seconds must be a positive integer');
});

it('rejects a non-int gap_warn_threshold', function (): void {
    expect(function (): void {
        ConfigValidator::validatePending(['gap_warn_threshold' => 1.5]);
    })->toThrow(QueueInsightsConfigException::class, 'pending.gap_warn_threshold must be a positive integer');
});

it('accepts an empty alerts block', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([]))->not->toThrow(Throwable::class);
});

it('rejects a non-bool alerts.enabled', function (): void {
    expect(fn () => ConfigValidator::validateAlerts(['enabled' => 'yes']))
        ->toThrow(QueueInsightsConfigException::class, 'alerts.enabled must be a boolean');
});

it('rejects a negative alerts.cooldown_seconds', function (): void {
    expect(fn () => ConfigValidator::validateAlerts(['cooldown_seconds' => -1]))
        ->toThrow(QueueInsightsConfigException::class, 'cooldown_seconds');
});

it('rejects an invalid severity in a depth threshold', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'depth' => [
                'thresholds' => [
                    ['connection' => 'sqs', 'queue' => 'work', 'depth' => 100, 'severity' => 'urgent'],
                ],
            ],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'warning, critical');
});

it('rejects slack channel enabled without webhook_url', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'slack' => ['enabled' => true],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'webhook_url');
});

it('rejects a non-string slack channel label', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'slack' => ['enabled' => false, 'channel' => 12345],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'slack.channel');
});

it('rejects mail channel enabled with empty to', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'mail' => ['enabled' => true, 'to' => []],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'mail.to');
});

it('rejects a non-int depth threshold value', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'thresholds' => [
            ['connection' => 'sqs', 'queue' => 'work', 'depth' => '1000'],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'depth must be a non-negative integer');
});

it('logs a deprecation warning when legacy alerts.thresholds is set', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'legacy `alerts.thresholds`'));

    ConfigValidator::validateAlerts([
        'thresholds' => [
            ['connection' => 'sqs', 'queue' => 'work', 'depth' => 1000],
        ],
    ]);
});

it('rejects ratio outside [0, 1]', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'failure_rate' => ['ratio' => 1.5],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'ratio');
});

it('accepts a well-formed retention block (or empty defaults)', function (): void {
    // No assertion — both calls returning without exception IS the assertion.
    // Throwing would fail the test; reaching the end means validation passed.
    ConfigValidator::validateRetention([]);
    ConfigValidator::validateRetention([
        'history_hours' => 24,
        'processed_counters_days' => 7,
        'failed_counters_days' => 30,
        'completed_stream_max' => 10000,
        'per_class_stream_max' => 1000,
        'per_connection_stream_max' => 5000,
    ]);
})->throwsNoExceptions();

it('rejects a non-int retention.per_connection_stream_max', function (): void {
    expect(fn () => ConfigValidator::validateRetention(['per_connection_stream_max' => '5000']))
        ->toThrow(QueueInsightsConfigException::class, 'retention.per_connection_stream_max must be a positive integer');
});

it('rejects a zero retention.per_connection_stream_max', function (): void {
    expect(fn () => ConfigValidator::validateRetention(['per_connection_stream_max' => 0]))
        ->toThrow(QueueInsightsConfigException::class, 'retention.per_connection_stream_max must be a positive integer');
});

it('rejects a negative retention.completed_stream_max', function (): void {
    expect(fn () => ConfigValidator::validateRetention(['completed_stream_max' => -1]))
        ->toThrow(QueueInsightsConfigException::class, 'retention.completed_stream_max must be a positive integer');
});

it('accepts an empty silenced list (feature off)', function (): void {
    ConfigValidator::validateSilenced([]);
})->throwsNoExceptions();

it('accepts well-formed FQCNs and synthetic class labels', function (): void {
    ConfigValidator::validateSilenced([
        'App\\Jobs\\Foo',
        'App\\Jobs\\Reports\\Daily',
        'Closure@abc123',
        'Encrypted@deadbeef',
    ]);
})->throwsNoExceptions();

it('rejects an associative silenced array', function (): void {
    expect(fn () => ConfigValidator::validateSilenced(['foo' => 'App\\Jobs\\Foo']))
        ->toThrow(QueueInsightsConfigException::class, 'silenced must be a list');
});

it('rejects a non-string silenced entry', function (): void {
    expect(fn () => ConfigValidator::validateSilenced([42]))
        ->toThrow(QueueInsightsConfigException::class, 'silenced[0] must be a non-empty string');
});

it('rejects an empty-string silenced entry', function (): void {
    expect(fn () => ConfigValidator::validateSilenced(['']))
        ->toThrow(QueueInsightsConfigException::class, 'silenced[0] must be a non-empty string');
});

it('rejects a regex-violating silenced entry', function (): void {
    expect(fn () => ConfigValidator::validateSilenced(['App\\Jobs\\Foo; DROP TABLE']))
        ->toThrow(QueueInsightsConfigException::class, 'is not a valid job-class label');
});

it('accepts an empty silenced_patterns list (feature off)', function (): void {
    ConfigValidator::validateSilencedPatterns([]);
})->throwsNoExceptions();

it('accepts well-formed glob patterns', function (): void {
    ConfigValidator::validateSilencedPatterns([
        'App\\Jobs\\Reports\\*',
        '*\\Internal\\*',
        'Closure@*',
    ]);
})->throwsNoExceptions();

it('rejects an associative silenced_patterns array', function (): void {
    expect(fn () => ConfigValidator::validateSilencedPatterns(['k' => 'App\\Jobs\\*']))
        ->toThrow(QueueInsightsConfigException::class, 'silenced_patterns must be a list');
});

it('rejects a non-string silenced_patterns entry', function (): void {
    expect(fn () => ConfigValidator::validateSilencedPatterns([42]))
        ->toThrow(QueueInsightsConfigException::class, 'silenced_patterns[0] must be a non-empty string');
});

it('rejects an empty-string silenced_patterns entry', function (): void {
    expect(fn () => ConfigValidator::validateSilencedPatterns(['']))
        ->toThrow(QueueInsightsConfigException::class, 'silenced_patterns[0] must be a non-empty string');
});

it('rejects a glob pattern with shell-injection chars', function (): void {
    expect(fn () => ConfigValidator::validateSilencedPatterns(['App\\Jobs\\*; DROP TABLE']))
        ->toThrow(QueueInsightsConfigException::class, 'is not a valid glob pattern');
});

it('boot-time silenced_patterns shape check fails loud on a non-array config', function (): void {
    config()->set('queue-insights.silenced_patterns', 'App\\Jobs\\*');

    expect(fn () => app()->register(QueueInsightsServiceProvider::class, true))
        ->toThrow(QueueInsightsConfigException::class, 'silenced_patterns must be a list of glob strings');
});

it('boot-time silenced shape check fails loud on a non-array config (codex review #2)', function (): void {
    // The other section validators silently coerce a non-array config to
    // [], but `silenced` is meant to be a list of FQCNs and a typo like
    // `'silenced' => 'App\\Jobs\\Foo'` (string instead of list) would
    // otherwise become "feature off" silently. Boot must throw.
    config()->set('queue-insights.silenced', 'App\\Jobs\\Foo');

    expect(fn () => app()->register(QueueInsightsServiceProvider::class, true))
        ->toThrow(QueueInsightsConfigException::class, 'must be a list of class-name strings');
});

it('accepts a well-formed prometheus block (or empty defaults)', function (): void {
    expect(function (): void {
        ConfigValidator::validatePrometheus([]);
        ConfigValidator::validatePrometheus([
            'enabled' => true,
            'path' => 'metrics',
            'middleware' => null,
            'token' => 'secret',
            'allow_ips' => ['10.0.0.0/8', '192.168.1.1'],
            'class_filter' => ['mode' => 'allow_list', 'classes' => ['App\\Jobs\\Foo'], 'top_n' => 50],
            'metrics' => ['queue_depth' => true, 'jobs_processed_total' => false],
            'cache_ttl_seconds' => 5,
        ]);
    })->not->toThrow(Throwable::class);
});

it('rejects a non-bool prometheus.enabled', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['enabled' => 'yes']))
        ->toThrow(QueueInsightsConfigException::class, 'prometheus.enabled');
});

it('rejects an empty prometheus.path', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['path' => '']))
        ->toThrow(QueueInsightsConfigException::class, 'prometheus.path');
});

it('rejects a non-string prometheus.token', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['token' => 42]))
        ->toThrow(QueueInsightsConfigException::class, 'prometheus.token');
});

it('accepts a null prometheus.token', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['token' => null]))
        ->not->toThrow(Throwable::class);
});

it('rejects a malformed allow_ips entry (not an IP)', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['allow_ips' => ['not-an-ip']]))
        ->toThrow(QueueInsightsConfigException::class, 'is not a valid IP or CIDR');
});

it('rejects a non-array allow_ips', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['allow_ips' => '10.0.0.0/8']))
        ->toThrow(QueueInsightsConfigException::class, 'allow_ips must be a list');
});

it('rejects an empty-string allow_ips entry', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['allow_ips' => ['']]))
        ->toThrow(QueueInsightsConfigException::class, 'must be a non-empty string');
});

it('rejects an unknown class_filter.mode', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['class_filter' => ['mode' => 'unknown']]))
        ->toThrow(QueueInsightsConfigException::class, 'class_filter.mode must be one of');
});

it('rejects a non-positive top_n', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['class_filter' => ['top_n' => 0]]))
        ->toThrow(QueueInsightsConfigException::class, 'class_filter.top_n must be a positive integer');
});

it('rejects a negative cache_ttl_seconds', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['cache_ttl_seconds' => -1]))
        ->toThrow(QueueInsightsConfigException::class, 'cache_ttl_seconds must be a non-negative integer');
});

it('accepts cache_ttl_seconds = 0 (cache disabled)', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['cache_ttl_seconds' => 0]))
        ->not->toThrow(Throwable::class);
});

it('rejects a non-bool metrics.* toggle', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['metrics' => ['queue_depth' => 'yes']]))
        ->toThrow(QueueInsightsConfigException::class, 'metrics.queue_depth must be a boolean');
});

it('rejects a non-string class_filter.classes entry', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['class_filter' => ['classes' => [42]]]))
        ->toThrow(QueueInsightsConfigException::class, 'must be a non-empty string');
});

it('rejects a non-array prometheus.middleware', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['middleware' => 'foo']))
        ->toThrow(QueueInsightsConfigException::class, 'middleware must be null or an array');
});

it('accepts a dashboard block without a theme key', function (): void {
    expect(fn () => ConfigValidator::validateDashboard([]))->not->toThrow(Throwable::class)
        ->and(fn () => ConfigValidator::validateDashboard(['enabled' => true, 'polling' => false]))->not->toThrow(Throwable::class);
});

it('accepts a well-formed dashboard.theme block', function (): void {
    expect(fn () => ConfigValidator::validateDashboard(['theme' => ['enabled' => true]]))
        ->not->toThrow(Throwable::class)
        ->and(fn () => ConfigValidator::validateDashboard(['theme' => ['enabled' => false]]))->not->toThrow(Throwable::class)
        ->and(fn () => ConfigValidator::validateDashboard(['theme' => []]))->not->toThrow(Throwable::class);
});

it('rejects a non-array dashboard.theme', function (): void {
    expect(fn () => ConfigValidator::validateDashboard(['theme' => 'on']))
        ->toThrow(QueueInsightsConfigException::class, 'dashboard.theme must be an array');
});

it('rejects a non-bool dashboard.theme.enabled', function (): void {
    expect(fn () => ConfigValidator::validateDashboard(['theme' => ['enabled' => 'yes']]))
        ->toThrow(QueueInsightsConfigException::class, 'dashboard.theme.enabled must be a boolean')
        ->and(fn () => ConfigValidator::validateDashboard(['theme' => ['enabled' => 1]]))
        ->toThrow(QueueInsightsConfigException::class, 'dashboard.theme.enabled must be a boolean');
});
