<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed store for the interim initiator hash `qi:initiator:{uuid}`.
 *
 * Mirrors `ChainLineageStore`'s interim-record pattern: the key holds the
 * `origin` / `call_site` pair for a job whose attribution can't ride
 * Laravel `Context` to the place that needs it — namely the failed-job
 * dashboard view (no job `Context`) and call-site transport (never in
 * `Context`).
 *
 * Per spec §2.2 the key is never `DEL`-ed: `RecordJobFailed` writes the
 * durable pair, `RecordJobProcessed` shortens the TTL to a 60s tail after
 * copying into the completed stream, and the key otherwise ages out via
 * its `initiator.ttl_seconds` TTL.
 */
final class InitiatorStore
{
    /**
     * HSET each non-empty field onto `qi:initiator:{uuid}`, then EXPIRE the
     * whole key. No-op when the uuid is empty, no fields survive the
     * empty-string filter, or the TTL is non-positive.
     *
     * @param  array<string, ?string>  $fields  e.g. ['origin' => 'http:...', 'call_site' => 'app/...:88']
     */
    public function write(string $uuid, array $fields, int $ttl): void
    {
        if ($uuid === '' || $ttl <= 0) {
            return;
        }

        $writable = [];
        foreach ($fields as $field => $value) {
            if (is_string($value) && $value !== '') {
                $writable[$field] = $value;
            }
        }

        if ($writable === []) {
            return;
        }

        $redis = $this->connection();
        $key = $this->key($uuid);

        // One HSET per field — keeps the call portable across phpredis
        // variants, same rationale as RecordJobQueued::writePendingTracking.
        foreach ($writable as $field => $value) {
            $redis->command('hset', [$key, $field, $value]);
        }

        $redis->command('expire', [$key, $ttl]);
    }

    /**
     * HGETALL the initiator hash. Returns `origin` / `call_site` as either a
     * non-empty string or null when the field (or the whole key) is absent.
     *
     * @return array{origin: ?string, call_site: ?string}
     */
    public function read(string $uuid): array
    {
        if ($uuid === '') {
            return ['origin' => null, 'call_site' => null];
        }

        $hash = $this->connection()->command('hgetall', [$this->key($uuid)]);
        if (! is_array($hash)) {
            return ['origin' => null, 'call_site' => null];
        }

        $origin = $hash['origin'] ?? null;
        $callSite = $hash['call_site'] ?? null;

        return [
            'origin' => is_string($origin) && $origin !== '' ? $origin : null,
            'call_site' => is_string($callSite) && $callSite !== '' ? $callSite : null,
        ];
    }

    /**
     * Shrink the now-redundant `qi:initiator:{uuid}` key to a short tail
     * once `RecordJobProcessed` has copied the fields into the completed
     * stream. Never `DEL` — see the class docblock.
     */
    public function shortenTtl(string $uuid, int $seconds = 60): void
    {
        if ($uuid === '' || $seconds <= 0) {
            return;
        }

        $this->connection()->command('expire', [$this->key($uuid), $seconds]);
    }

    /**
     * @return non-empty-string
     */
    public function key(string $uuid): string
    {
        return KeyPrefix::make("initiator:{$uuid}");
    }

    private function connection(): RedisConnection
    {
        return Redis::connection(Config::string('redis_connection', 'default'));
    }
}
