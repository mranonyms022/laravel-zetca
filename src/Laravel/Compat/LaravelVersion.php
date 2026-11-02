<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Compat;

use Illuminate\Foundation\Application;

/**
 * Single place for version checks. Every runtime branch on the Laravel version must be
 * covered by a test in the CI matrix (ARCHITECTURE 5.3).
 */
final class LaravelVersion
{
    public static function major(): int
    {
        return (int) explode('.', self::full())[0];
    }

    public static function full(): string
    {
        return class_exists(Application::class) ? Application::VERSION : app()->version();
    }

    public static function atLeast(int $major): bool
    {
        return self::major() >= $major;
    }
}
