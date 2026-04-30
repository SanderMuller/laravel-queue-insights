<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use RuntimeException;

final class LuaScripts
{
    private static ?string $updateMaxDuration = null;

    private static ?string $markInFlight = null;

    private static ?string $pushChainClaim = null;

    public static function updateMaxDuration(): string
    {
        return self::$updateMaxDuration ??= self::load(__DIR__ . '/Lua/UpdateMaxDuration.lua');
    }

    public static function markInFlight(): string
    {
        return self::$markInFlight ??= self::load(__DIR__ . '/Lua/MarkInFlight.lua');
    }

    public static function pushChainClaim(): string
    {
        return self::$pushChainClaim ??= self::load(__DIR__ . '/Lua/PushChainClaim.lua');
    }

    private static function load(string $path): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Unable to load Lua script at [{$path}].");
        }

        return $content;
    }
}
