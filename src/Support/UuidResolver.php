<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Resolves a job uuid to the dashboard surface it lives on:
 *
 *   - `completed` → `qi:uuid-completed:{uuid}` holds the global completed
 *     stream id; opens via `QueueInsightsDashboard::openPayload($id)`.
 *   - `failed`    → `qi:uuid-failed:{uuid}` holds the failed_jobs row id;
 *     opens via `openFailed($id)`.
 *   - `pending`   → `qi:pending:{uuid}` hash exists; opens via
 *     `openPending($uuid)`.
 *
 * Used by the chain-lineage `↰ From` row's click-through. Returns null when
 * the uuid has aged out of every retention window — the caller surfaces a
 * "no longer available" flash instead of silently navigating nowhere.
 *
 * `qi:uuid-completed:{uuid}` and `qi:uuid-failed:{uuid}` are written
 * unconditionally when chain-lineage is enabled (with the
 * `chain_lineage.lineage_ttl_seconds` TTL) so click-through works even
 * for hosts that have batches disabled.
 */
final class UuidResolver
{
    /**
     * @return array{type: 'completed', id: string}|array{type: 'failed', id: int}|array{type: 'pending', id: string}|null
     */
    public static function resolve(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        try {
            $redis = Redis::connection(Config::string('redis_connection', 'default'));

            $completed = $redis->command('get', [KeyPrefix::make("uuid-completed:{$uuid}")]);
            if (is_string($completed) && $completed !== '') {
                return ['type' => 'completed', 'id' => $completed];
            }

            $failed = $redis->command('get', [KeyPrefix::make("uuid-failed:{$uuid}")]);
            if (is_numeric($failed)) {
                return ['type' => 'failed', 'id' => (int) $failed];
            }

            $pendingExists = $redis->command('exists', [KeyPrefix::make("pending:{$uuid}")]);
            if (is_numeric($pendingExists) && (int) $pendingExists === 1) {
                return ['type' => 'pending', 'id' => $uuid];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
