<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

final class KeyPrefix
{
    public static function make(string $suffix): string
    {
        return Config::string('key_prefix', 'qm:') . $suffix;
    }
}
