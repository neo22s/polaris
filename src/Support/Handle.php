<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Turns a display name into a public handle.
 *
 * "Aleix González Morera" becomes "aleix-gonzalez-morera". The result always
 * satisfies the handle pattern, so a name typed with accents, capitals or
 * punctuation still produces a usable URL.
 */
final class Handle
{
    /** Maximum length, matching the handle pattern. */
    public const MAX_LENGTH = 30;

    public static function slug(string $name): string
    {
        // Fold accents: "González" -> "Gonzalez". The decomposition form must be
        // asked for explicitly; the default keeps the character composed.
        $name = self::deaccent($name);

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        // A single leading or trailing hyphen is invalid; the pattern also wants
        // at least three characters.
        if (mb_strlen($slug) < 3) {
            return '';
        }

        return mb_substr($slug, 0, self::MAX_LENGTH);
    }

    /**
     * Split composed characters into letter + accent, then drop the accents.
     */
    private static function deaccent(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $value = $decomposed;
            }
        }

        // Mn is the Unicode "nonspacing mark" category, which is what accents
        // become once decomposed.
        return (string) preg_replace('/\p{Mn}+/u', '', $value);
    }

    /**
     * A handle that is not taken yet, adding a numeric suffix when needed.
     *
     * @param callable(string):bool $isTaken
     */
    public static function unique(string $name, callable $isTaken): ?string
    {
        $base = self::slug($name);

        if ($base === '') {
            return null;
        }

        if (!$isTaken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 100; $suffix++) {
            $candidate = self::trimToFit($base, '-' . $suffix);

            if (!$isTaken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Shorten the base so the suffix still fits the maximum length.
     */
    private static function trimToFit(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - mb_strlen($suffix);

        if ($room < 3) {
            $room = 3;
        }

        return rtrim(mb_substr($base, 0, $room), '-') . $suffix;
    }
}
