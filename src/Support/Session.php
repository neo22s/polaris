<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Small wrapper around $_SESSION so controllers and services do not depend on
 * the superglobal directly.
 */
final class Session
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::remove($key);

        return $value;
    }

    /** @return array<int|string,mixed> */
    public static function all(): array
    {
        return $_SESSION ?? [];
    }

    /** @param array<string,mixed> $values */
    public static function replace(array $values): void
    {
        $_SESSION = $values;
    }

    /** @param array<int,string> $keys */
    public static function keepOnly(array $keys): void
    {
        $_SESSION = array_intersect_key($_SESSION, array_flip($keys));
    }
}
