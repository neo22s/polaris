<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * URL helpers shared by redirect-sensitive flows.
 */
final class Url
{
    public static function safePath(mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === ''
            || $path[0] !== '/'
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        $parts = parse_url($path);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $path;
    }
}
