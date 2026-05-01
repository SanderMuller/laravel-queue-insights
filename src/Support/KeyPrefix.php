<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

final class KeyPrefix
{
    /**
     * @return non-empty-string
     */
    public static function make(string $suffix): string
    {
        $prefix = Config::string('key_prefix', 'qm:');
        if ($prefix === '') {
            $prefix = 'qm:';
        }

        return $prefix . $suffix;
    }

    /**
     * Per-class key under the multi-connection-scoping dual-write shape.
     * Listeners write the (`{prefix}:{class}`) and (`{prefix}:{class}:{connection}`)
     * variants; readers select the variant by passing the connection or null.
     * Centralised here so writer and reader can't drift on key shape.
     *
     * @return non-empty-string
     */
    public static function classKey(string $prefix, string $class, ?string $connection = null): string
    {
        return self::make(
            $connection === null
                ? "{$prefix}:{$class}"
                : "{$prefix}:{$class}:{$connection}",
        );
    }
}
