<?php

declare(strict_types=1);

namespace App\Core;

final class RequestContext
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= bin2hex(random_bytes(16));
    }

    public static function set(string $id): void
    {
        self::$id = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) ? $id : bin2hex(random_bytes(16));
    }
}
