<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed store for the per-failure debug-context hash
 * `qi:failure-ctx:{uuid}`. Mirrors `InitiatorStore`'s interim-record pattern:
 * `RecordJobFailed` writes the sanitized Context + environment snapshot; the
 * failed-job dashboard modal + markdown export read it back by uuid. Ages out
 * via `failure_context.ttl_seconds` (7d default) — failures are rare so the
 * write cost is negligible.
 */
final class FailureContextStore
{
    /**
     * HSET the `app_context` / `environment` JSON blobs, then EXPIRE. No-op
     * when the uuid is empty, the TTL is non-positive, or both sections are
     * empty (nothing worth a Redis write).
     *
     * @param  array{app_context: array<array-key, mixed>, environment: array<string, scalar|null>}  $context
     */
    public function write(string $uuid, array $context, int $ttl): void
    {
        if ($uuid === '' || $ttl <= 0) {
            return;
        }

        $fields = [];
        if ($context['app_context'] !== []) {
            $fields['app_context'] = $this->encode($context['app_context']);
        }

        if ($context['environment'] !== []) {
            $fields['environment'] = $this->encode($context['environment']);
        }

        if ($fields === []) {
            return;
        }

        $redis = $this->connection();
        $key = $this->key($uuid);

        // One HSET per field — portable across phpredis variants, same
        // rationale as InitiatorStore::write.
        foreach ($fields as $field => $value) {
            $redis->command('hset', [$key, $field, $value]);
        }

        $redis->command('expire', [$key, $ttl]);
    }

    /**
     * HGETALL the failure-context hash, decoding both JSON sections. Returns
     * empty arrays when the field (or the whole key) is absent.
     *
     * @return array{app_context: array<array-key, mixed>, environment: array<array-key, mixed>}
     */
    public function read(string $uuid): array
    {
        $empty = ['app_context' => [], 'environment' => []];
        if ($uuid === '') {
            return $empty;
        }

        $hash = $this->connection()->command('hgetall', [$this->key($uuid)]);
        if (! is_array($hash)) {
            return $empty;
        }

        return [
            'app_context' => $this->decode($hash['app_context'] ?? null),
            'environment' => $this->decode($hash['environment'] ?? null),
        ];
    }

    /**
     * @return non-empty-string
     */
    public function key(string $uuid): string
    {
        return KeyPrefix::make("failure-ctx:{$uuid}");
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function connection(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
