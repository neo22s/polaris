<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * File operations that tolerate failure but never hide it.
 *
 * Shared hosting denies permissions, unlinks files underneath us and fills
 * disks. The kernel has to keep serving pages when that happens, so the raw
 * PHP functions must be silenced; doing `@` at every call site made failures
 * invisible even in logs. These wrappers return the same values as PHP and
 * record a `[Polaris]` line when an operation actually failed.
 */
final class SafeFilesystem
{
    public static function read(string $path): string|false
    {
        $result = @file_get_contents($path);

        // A missing cache file is a normal miss, not a failure worth a log line.
        if ($result === false && file_exists($path)) {
            self::log('read', $path);
        }

        return $result;
    }

    public static function write(string $path, string $contents, int $flags = 0): int|false
    {
        $result = @file_put_contents($path, $contents, $flags);

        if ($result === false) {
            self::log('write', $path);
        }

        return $result;
    }

    public static function delete(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }

        $result = @unlink($path);

        if ($result === false) {
            self::log('delete', $path);
        }

        return $result;
    }

    public static function makeDirectory(string $path, int $mode = 0775): bool
    {
        if (is_dir($path)) {
            return true;
        }

        $result = @mkdir($path, $mode, true) || is_dir($path);

        if ($result === false) {
            self::log('mkdir', $path);
        }

        return $result;
    }

    public static function removeDirectory(string $path): bool
    {
        $result = @rmdir($path);

        if ($result === false) {
            self::log('rmdir', $path);
        }

        return $result;
    }

    public static function touch(string $path): bool
    {
        $result = @touch($path);

        if ($result === false) {
            self::log('touch', $path);
        }

        return $result;
    }

    public static function modifiedAt(string $path): int|false
    {
        $result = @filemtime($path);

        if ($result === false) {
            self::log('filemtime', $path);
        }

        return $result;
    }

    public static function size(string $path): int|false
    {
        $result = @filesize($path);

        if ($result === false) {
            self::log('filesize', $path);
        }

        return $result;
    }

    public static function move(string $from, string $to): bool
    {
        $result = @rename($from, $to);

        if ($result === false) {
            self::log('rename', $from . ' -> ' . $to);
        }

        return $result;
    }

    public static function chmod(string $path, int $mode): bool
    {
        $result = @chmod($path, $mode);

        if ($result === false) {
            self::log('chmod', $path);
        }

        return $result;
    }

    public static function permissions(string $path): int|false
    {
        $result = @fileperms($path);

        if ($result === false) {
            self::log('fileperms', $path);
        }

        return $result;
    }

    private static function log(string $operation, string $path): void
    {
        error_log('[Polaris] filesystem ' . $operation . ' failed: ' . $path);
    }
}
