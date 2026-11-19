<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * The one format used for timestamps stored in MySQL.
 *
 * All comparisons in the kernel happen in SQL against these strings, so every
 * writer has to produce exactly the same shape. Scattering the format across
 * repositories invites a `Y-m-d` value into a `datetime` column, where string
 * comparison silently breaks expiry checks.
 */
final class Clock
{
    public const SQL_FORMAT = 'Y-m-d H:i:s';

    public static function now(): string
    {
        return self::at(time());
    }

    public static function at(int $timestamp): string
    {
        return date(self::SQL_FORMAT, $timestamp);
    }
}
