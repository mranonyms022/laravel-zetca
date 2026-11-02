<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the prebuilt dashboard assets from the package (no publish or host build step needed). */
final class AssetController
{
    private const FILES = [
        'zatca.css' => 'text/css; charset=UTF-8',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);
        $path = dirname(__DIR__, 5) . '/resources/dist/' . $file;
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Cache-busting version for asset URLs. */
    public static function version(string $file): string
    {
        $path = dirname(__DIR__, 5) . '/resources/dist/' . $file;

        return is_file($path) ? substr(md5_file($path) ?: '', 0, 10) : '0';
    }
}
