<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Look up a job class by uuid for the backward-chain lineage UI.
 *
 * `qi:class:{uuid}` is written with a 7-day TTL by `RecordJobProcessed` and
 * `RecordJobFailed` so a child's `parent_uuid` can be hydrated to a class
 * label without scanning the completed stream. Misses are expected once the
 * parent ages out of retention; the dashboard shows the uuid alone in that
 * case (per spec §4: "omit class label if the parent has aged out").
 */
final class ParentClassResolver
{
    public static function classKey(string $uuid): string
    {
        return KeyPrefix::make("class:{$uuid}");
    }

    public static function resolve(string $uuid): ?string
    {
        if ($uuid === '') {
            return null;
        }

        try {
            $value = Redis::connection(Config::string('redis_connection', 'default'))
                ->command('get', [self::classKey($uuid)]);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Batch lookup. Returns a uuid → class map for hits; misses are simply
     * absent. Used by `RowEnricher::failed` to hydrate parent classes
     * without N round-trips on a paged failed-rows list.
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    public static function resolveMany(array $uuids): array
    {
        $unique = array_values(array_unique(array_filter(
            $uuids,
            static fn (string $u): bool => $u !== '',
        )));

        if ($unique === []) {
            return [];
        }

        $keys = array_map(self::classKey(...), $unique);

        try {
            $values = Redis::connection(Config::string('redis_connection', 'default'))
                ->command('mget', [$keys]);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($unique as $i => $uuid) {
            $value = $values[$i] ?? null;
            if (is_string($value) && $value !== '') {
                $out[$uuid] = $value;
            }
        }

        return $out;
    }
}
