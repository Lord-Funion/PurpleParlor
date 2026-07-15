<?php

declare(strict_types=1);

namespace App\Security;

final class UrlGuard
{
    public static function localRedirect(?string $target, string $fallback = '/'): string
    {
        if (!is_string($target) || $target === '' || $target[0] !== '/' || str_starts_with($target, '//')) {
            return $fallback;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target)) {
            return $fallback;
        }
        $parts = parse_url($target);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }
        return $target;
    }

    public static function safePath(string $base, string $relative): ?string
    {
        if (str_contains($relative, "\0") || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $relative)) {
            return null;
        }
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }
        $candidate = realpath($baseReal . DIRECTORY_SEPARATOR . ltrim($relative, '/\\'));
        if ($candidate === false || !str_starts_with($candidate . DIRECTORY_SEPARATOR, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $candidate;
    }
}
