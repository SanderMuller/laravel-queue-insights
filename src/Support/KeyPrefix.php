<?php

declare(strict_types=1);

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
}
