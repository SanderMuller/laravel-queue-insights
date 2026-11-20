<?php declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use RuntimeException;

final class LuaScripts
{
    private static ?string $updateMaxDuration = null;

    private static ?string $markInFlight = null;

    private static ?string $pushChainClaim = null;

    private static ?string $batchClaimConnection = null;

    private static ?string $incrPairWithExpire = null;

    private static ?string $durationPair = null;

    private static ?string $samplesPair = null;

    private static ?string $setexPair = null;

    private static ?string $classesRoster = null;

    private static ?string $rewriteScheduleSnapshot = null;

    private static ?string $batchFetchCompletedMeta = null;

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

    public static function batchClaimConnection(): string
    {
        return self::$batchClaimConnection ??= self::load(__DIR__ . '/Lua/BatchClaimConnection.lua');
    }

    public static function incrPairWithExpire(): string
    {
        return self::$incrPairWithExpire ??= self::load(__DIR__ . '/Lua/IncrPairWithExpire.lua');
    }

    public static function durationPair(): string
    {
        return self::$durationPair ??= self::load(__DIR__ . '/Lua/DurationPair.lua');
    }

    public static function samplesPair(): string
    {
        return self::$samplesPair ??= self::load(__DIR__ . '/Lua/SamplesPair.lua');
    }

    public static function setexPair(): string
    {
        return self::$setexPair ??= self::load(__DIR__ . '/Lua/SetexPair.lua');
    }

    public static function classesRoster(): string
    {
        return self::$classesRoster ??= self::load(__DIR__ . '/Lua/ClassesRoster.lua');
    }

    public static function rewriteScheduleSnapshot(): string
    {
        return self::$rewriteScheduleSnapshot ??= self::load(__DIR__ . '/Lua/RewriteScheduleSnapshot.lua');
    }

    public static function batchFetchCompletedMeta(): string
    {
        return self::$batchFetchCompletedMeta ??= self::load(__DIR__ . '/Lua/BatchFetchCompletedMeta.lua');
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
