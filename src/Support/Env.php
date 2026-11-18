<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Reads environment values with the types a .env file implies.
 *
 * Replaces vlucas/phpdotenv with the small subset a starter kit needs:
 *
 *     KEY=value
 *     KEY="quoted value"
 *     KEY='single quoted'
 *     # comment
 *     EXPORT KEY=value
 *
 * The file is read once, and the result is cached for the request. Parsing
 * happens before the container is built, so this class must not depend on it.
 */
final class Env
{
    private static bool $loaded = false;

    /** @var array<string,string> */
    private static array $values = [];

    /**
     * Load a .env file into the process environment.
     *
     * Missing files are not an error: production usually supplies real
     * environment variables and ships no .env at all.
     */
    public static function load(string $file): void
    {
        self::$loaded = true;

        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            // Quoted values keep their spaces and inline # literally.
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            } else {
                // An unquoted value ends at the first unescaped # comment.
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            // A real environment variable wins: the hosting, or a test
            // bootstrap, may have set it before the file was read.
            if (getenv($key) !== false) {
                continue;
            }

            self::$values[$key] = $value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)', '1', 'yes', 'on' => true,
            'false', '(false)', '0', 'no', 'off' => false,
            default => $default,
        };
    }

    /**
     * A comma-separated list, trimmed and without empty entries.
     *
     * @return array<int,string>
     */
    public static function list(string $key): array
    {
        $value = self::get($key);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::$values;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    /** Reset the cache. Tests only. */
    public static function reset(): void
    {
        self::$loaded = false;
        self::$values = [];
    }
}
