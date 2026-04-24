<?php

declare(strict_types=1);

namespace SanderMuller\QueueInsights\Support;

use InvalidArgumentException;

final class CanonicalQueueKey
{
    public static function from(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('Queue input is empty.');
        }

        if (preg_match('/^https?:\/\//i', $input) === 1) {
            $lastSlash = strrpos($input, '/');
            $candidate = $lastSlash === false ? $input : substr($input, $lastSlash + 1);
        } else {
            $candidate = $input;
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $candidate) ?? '';

        if ($normalized === '') {
            throw new InvalidArgumentException("Queue input [{$input}] normalizes to an empty key.");
        }

        return $normalized;
    }
}
