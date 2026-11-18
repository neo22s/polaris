<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Merges the kernel defaults with an application's overrides.
 *
 * The merge is recursive and the application wins at the leaf level, so a site
 * that only wants to change the sender address writes:
 *
 *     ['settings' => ['mail' => ['from' => 'hello@site.com']]]
 *
 * and keeps every other mail key from the kernel.
 *
 * Lists are replaced, never merged: a site that supplies its own admin list
 * means exactly that list.
 */
final class Config
{
    /** @var array<string,mixed>|null Merged settings, for helpers without the container. */
    private static ?array $current = null;

    /**
     * @param array<array-key,mixed> $defaults
     * @param array<array-key,mixed> $overrides
     * @return array<array-key,mixed>
     */
    public static function merge(array $defaults, array $overrides): array
    {
        $merged = self::mergeRecursive($defaults, $overrides);

        self::$current = $merged['settings'] ?? null;

        return $merged;
    }

    /**
     * @param array<array-key,mixed> $defaults
     * @param array<array-key,mixed> $overrides
     * @return array<array-key,mixed>
     */
    private static function mergeRecursive(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                // An explicit null means "drop this key", which is how an
                // application turns off something it does not use.
                unset($defaults[$key]);
                continue;
            }

            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($value)) {
                $defaults[$key] = self::mergeRecursive($defaults[$key], $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$current ?? [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$current ?? [];

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
