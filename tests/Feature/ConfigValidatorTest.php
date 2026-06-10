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

it('rejects a non-bool sentry channel enabled flag', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'sentry' => ['enabled' => 'yes'],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'alerts.channels.sentry.enabled must be a boolean');
});

it('accepts a bool sentry channel enabled flag', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'channels' => [
            'sentry' => ['enabled' => true],
        ],
    ]))->not->toThrow(QueueInsightsConfigException::class);
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

it('accepts a well-formed job_failed rule', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'job_failed' => ['enabled' => true, 'notify' => false, 'severity' => 'critical'],
        ],
    ]))->not->toThrow(Throwable::class);
});

it('rejects a non-bool job_failed.notify', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'job_failed' => ['notify' => 'yes'],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'alerts.rules.job_failed.notify must be a boolean');
});

it('rejects a non-bool job_failed.enabled', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'job_failed' => ['enabled' => 'yes'],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'alerts.rules.job_failed.enabled must be a boolean');
});

it('rejects an invalid job_failed.severity', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'job_failed' => ['severity' => 'urgent'],
        ],
    ]))->toThrow(QueueInsightsConfigException::class, 'warning, critical');
});

it('tolerates a published config missing the job_failed key', function (): void {
    expect(fn () => ConfigValidator::validateAlerts([
        'rules' => [
            'failure_rate' => ['enabled' => true],
        ],
    ]))->not->toThrow(Throwable::class);
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

it('rejects an unknown task_filter.mode', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['task_filter' => ['mode' => 'top_n_by_recency']]))
        ->toThrow(QueueInsightsConfigException::class, 'task_filter.mode must be one of');
});

it('rejects a non-array task_filter.tasks', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['task_filter' => ['tasks' => 'foo']]))
        ->toThrow(QueueInsightsConfigException::class, 'task_filter.tasks must be a list');
});

it('rejects a non-string task_filter.tasks entry', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus(['task_filter' => ['tasks' => ['']]]))
        ->toThrow(QueueInsightsConfigException::class, 'must be a non-empty string');
});

it('accepts a well-formed task_filter block', function (): void {
    expect(fn () => ConfigValidator::validatePrometheus([
        'task_filter' => ['mode' => 'allow_list', 'tasks' => ['task-key-a', 'task-key-b']],
    ]))->not->toThrow(Throwable::class);
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

it('accepts and type-checks dashboard.theme.cloud_enabled', function (): void {
    expect(fn () => ConfigValidator::validateDashboard(['theme' => ['cloud_enabled' => true]]))
        ->not->toThrow(Throwable::class)
        ->and(fn () => ConfigValidator::validateDashboard(['theme' => ['cloud_enabled' => 'yes']]))
        ->toThrow(QueueInsightsConfigException::class, 'dashboard.theme.cloud_enabled must be a boolean');
});

it('rejects a non-bool dashboard.theme.enabled', function (): void {
    expect(fn () => ConfigValidator::validateDashboard(['theme' => ['enabled' => 'yes']]))
        ->toThrow(QueueInsightsConfigException::class, 'dashboard.theme.enabled must be a boolean')
        ->and(fn () => ConfigValidator::validateDashboard(['theme' => ['enabled' => 1]]))
        ->toThrow(QueueInsightsConfigException::class, 'dashboard.theme.enabled must be a boolean');
});

it('accepts an empty horizon block', function (): void {
    expect(fn () => ConfigValidator::validateHorizon([]))->not->toThrow(Throwable::class);
});

it('accepts a well-formed horizon block', function (): void {
    expect(fn () => ConfigValidator::validateHorizon(['autodiscover' => true, 'environment' => 'production']))
        ->not->toThrow(Throwable::class)
        ->and(fn () => ConfigValidator::validateHorizon(['autodiscover' => false, 'environment' => null]))
        ->not->toThrow(Throwable::class)
        // 'force' is the tri-state escape hatch — accepted alongside bools.
        ->and(fn () => ConfigValidator::validateHorizon(['autodiscover' => 'force']))
        ->not->toThrow(Throwable::class);
});

it('rejects a horizon.autodiscover that is neither a bool nor the literal force', function (): void {
    foreach (['yes', 1, 'Force', 'always'] as $invalid) {
        expect(fn () => ConfigValidator::validateHorizon(['autodiscover' => $invalid]))
            ->toThrow(QueueInsightsConfigException::class, "must be true, false, or the string 'force'");
    }
});

it('rejects a non-string or empty horizon.environment', function (): void {
    expect(fn () => ConfigValidator::validateHorizon(['environment' => '']))
        ->toThrow(QueueInsightsConfigException::class, 'horizon.environment must be a non-empty string or null')
        ->and(fn () => ConfigValidator::validateHorizon(['environment' => 42]))
        ->toThrow(QueueInsightsConfigException::class, 'horizon.environment must be a non-empty string or null');
});

it('accepts an empty connection_aliases map', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases([]))->not->toThrow(Throwable::class);
});

it('accepts identity mappings in connection_aliases', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases(['redis' => 'redis']))
        ->not->toThrow(Throwable::class);
});

it('accepts a flat alias map with multiple sources pointing to one canonical', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases([
        'redis' => 'redis-staging',
        'redis-staging' => 'redis-staging',
        'redis-legacy' => 'redis-staging',
    ]))->not->toThrow(Throwable::class);
});

it('rejects a non-string connection_aliases key', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases([0 => 'redis-staging']))
        ->toThrow(QueueInsightsConfigException::class, 'connection_aliases keys must be non-empty strings');
});

it('rejects a non-string connection_aliases value', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases(['redis' => 42]))
        ->toThrow(QueueInsightsConfigException::class, "connection_aliases['redis'] must be a non-empty string");
});

it('rejects an empty-string connection_aliases value', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases(['redis' => '']))
        ->toThrow(QueueInsightsConfigException::class, "connection_aliases['redis'] must be a non-empty string");
});

it('rejects a transitive chain A => B => C', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases([
        'redis' => 'redis-staging',
        'redis-staging' => 'redis-prod',
    ]))->toThrow(QueueInsightsConfigException::class, 'transitive chain rejected');
});

it('rejects a mutual cycle A => B, B => A', function (): void {
    expect(fn () => ConfigValidator::validateConnectionAliases([
        'redis' => 'redis-staging',
        'redis-staging' => 'redis',
    ]))->toThrow(QueueInsightsConfigException::class, 'transitive chain rejected');
});

it('rejects an alias key containing Redis glob metacharacters', function (): void {
    // Pre-fix: the migration command builds `KEYS pending-zset:{from}:*`
    // directly from the alias key. A `*`/`?`/`[]`/`\` in the key would match
    // unrelated zsets and the subsequent ZADD/DEL would shred them.
    expect(fn () => ConfigValidator::validateConnectionAliases(['redis-*' => 'redis-staging']))
        ->toThrow(QueueInsightsConfigException::class, 'must not contain Redis glob metacharacters')
        ->and(fn () => ConfigValidator::validateConnectionAliases(['redis-?' => 'redis-staging']))
        ->toThrow(QueueInsightsConfigException::class, 'must not contain Redis glob metacharacters')
        ->and(fn () => ConfigValidator::validateConnectionAliases(['redis-[abc]' => 'redis-staging']))
        ->toThrow(QueueInsightsConfigException::class, 'must not contain Redis glob metacharacters');
});

it('rejects an alias value containing Redis glob metacharacters', function (): void {
    // The value lands in the `pending-zset:{to}:{queue}` write key on the
    // migration side and in canonical zset keys at runtime. Globs there
    // would write to unusable wildcard-named keys.
    expect(fn () => ConfigValidator::validateConnectionAliases(['redis' => 'redis-*']))
        ->toThrow(QueueInsightsConfigException::class, 'must not contain Redis glob metacharacters');
});

it('rejects snapshots collision under post-alias canonical connections', function (): void {
    config()->set('queue-insights.connection_aliases', ['redis' => 'redis-staging']);

    expect(fn () => ConfigValidator::validateSnapshots([
        ['connection' => 'redis', 'queue' => 'foo'],
        ['connection' => 'redis-staging', 'queue' => 'foo'],
    ]))->toThrow(QueueInsightsConfigException::class, 'collision on connection [redis-staging]');
});

// --- initiator -------------------------------------------------------------

it('accepts a well-formed initiator block (or empty defaults)', function (): void {
    expect(function (): void {
        ConfigValidator::validateInitiator([]);
        ConfigValidator::validateInitiator([
            'enabled' => true,
            'capture_origin' => true,
            'capture_call_site' => false,
            'call_site_max_depth' => 30,
            'ttl_seconds' => 604800,
            'context_key' => 'qi_origin',
        ]);
    })->not->toThrow(Throwable::class);
});

it('rejects a non-boolean initiator.enabled', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['enabled' => 'yes']))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.enabled must be a boolean');
});

it('rejects a non-boolean initiator.capture_origin', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['capture_origin' => 1]))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.capture_origin must be a boolean');
});

it('rejects a non-boolean initiator.capture_call_site', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['capture_call_site' => 'off']))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.capture_call_site must be a boolean');
});

it('rejects a non-int initiator.call_site_max_depth', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['call_site_max_depth' => '30']))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.call_site_max_depth must be a positive integer');
});

it('rejects a zero initiator.call_site_max_depth', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['call_site_max_depth' => 0]))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.call_site_max_depth must be a positive integer');
});

it('rejects a negative initiator.ttl_seconds', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['ttl_seconds' => -1]))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.ttl_seconds must be a positive integer');
});

it('rejects an empty initiator.context_key', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['context_key' => '']))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.context_key must be a non-empty string');
});

it('rejects a non-string initiator.context_key', function (): void {
    expect(fn () => ConfigValidator::validateInitiator(['context_key' => 123]))
        ->toThrow(QueueInsightsConfigException::class, 'initiator.context_key must be a non-empty string');
});

it('accepts a well-formed failure_context block', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext([
        'enabled' => true,
        'capture_app_context' => true,
        'context_keys' => ['user_id', 'tenant'],
        'capture_environment' => false,
        'release_resolver' => 'app.version',
        'max_value_bytes' => 1024,
        'ttl_seconds' => 3600,
    ]))->not->toThrow(Throwable::class);
});

it('accepts an empty failure_context block (missing-key tolerant)', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext([]))->not->toThrow(Throwable::class);
});

it('rejects a non-bool failure_context.capture_app_context', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext(['capture_app_context' => 'yes']))
        ->toThrow(QueueInsightsConfigException::class, 'failure_context.capture_app_context must be a boolean');
});

it('rejects a non-positive failure_context.max_value_bytes', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext(['max_value_bytes' => 0]))
        ->toThrow(QueueInsightsConfigException::class, 'failure_context.max_value_bytes must be a positive integer');
});

it('rejects a non-list failure_context.context_keys', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext(['context_keys' => ['k' => 'v']]))
        ->toThrow(QueueInsightsConfigException::class, 'context_keys must be a list of strings');
});

it('rejects a non-string entry in failure_context.context_keys', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext(['context_keys' => ['ok', 123]]))
        ->toThrow(QueueInsightsConfigException::class, 'context_keys entries must be non-empty strings');
});

it('rejects a non-string/non-callable failure_context.release_resolver', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext(['release_resolver' => 123]))
        ->toThrow(QueueInsightsConfigException::class, 'release_resolver must be null, a config-key string, or a callable');
});

it('accepts a callable failure_context.release_resolver', function (): void {
    expect(fn () => ConfigValidator::validateFailureContext(['release_resolver' => fn (): string => '1.0']))
        ->not->toThrow(Throwable::class);
});
