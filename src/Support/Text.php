<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Small text helpers for form input.
 */
final class Text
{
    /**
     * A trimmed string, or null when there is nothing to store.
     *
     * An empty form field and a missing one mean the same thing, so they
     * both end up as null instead of an empty string in the database.
     */
    public static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
