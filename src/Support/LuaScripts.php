<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use RuntimeException;

final class LuaScripts
{
    private static ?string $updateMaxDuration = null;

    public static function updateMaxDuration(): string
    {
        if (self::$updateMaxDuration !== null) {
            return self::$updateMaxDuration;
        }

        $path = __DIR__ . '/Lua/UpdateMaxDuration.lua';

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Unable to load Lua script at [{$path}].");
        }

        return self::$updateMaxDuration = $content;
    }
}
