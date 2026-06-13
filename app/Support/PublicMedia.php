<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class PublicMedia
{
    public static function url(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return Storage::disk('public')->url(ltrim($path, '/'));
    }

    /**
     * @param  array<int, string>|null  $paths
     * @return list<string>
     */
    public static function urls(?array $paths): array
    {
        if ($paths === null || $paths === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $path): ?string => is_string($path) ? self::url($path) : null,
            $paths,
        )));
    }

    /**
     * @return list<string>
     */
    public static function galleryUrls(?string $coverPath, ?array $imagePaths): array
    {
        $urls = self::urls($imagePaths);
        $coverUrl = self::url($coverPath);
        if ($coverUrl !== null && ! in_array($coverUrl, $urls, true)) {
            array_unshift($urls, $coverUrl);
        }

        return $urls;
    }
}
