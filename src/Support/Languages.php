<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * The name of a language, written in that language.
 *
 * A switcher has to list "English" and "Español", never "English" and
 * "Spanish": a visitor who cannot read the current language must still
 * recognise their own. `Locale::getDisplayLanguage($code, $code)` does that,
 * and the small map is the fallback for hosts without `intl` (they are common
 * on the cheap plans this kit targets).
 */
final class Languages
{
    /**
     * Endonyms for the languages a starter kit is likely to ship.
     *
     * @var array<string,string>
     */
    private const NAMES = [
        'en' => 'English',
        'es' => 'Español',
        'ca' => 'Català',
        'eu' => 'Euskara',
        'gl' => 'Galego',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'ru' => 'Русский',
        'uk' => 'Українська',
        'tr' => 'Türkçe',
        'ar' => 'العربية',
        'he' => 'עברית',
        'ja' => '日本語',
        'ko' => '한국어',
        'zh' => '中文',
        'hi' => 'हिन्दी',
    ];

    /**
     * The language's own name.
     *
     * @param string $code Locale code, for example "es" or "pt-BR".
     */
    public static function name(string $code): string
    {
        $normalized = self::normalize($code);

        if (class_exists(\Locale::class)) {
            $display = \Locale::getDisplayLanguage($code, $code);

            if (is_string($display) && $display !== '') {
                // intl returns "español" in the language's own casing rules,
                // which for a switcher reads better as "Español".
                return mb_convert_case($display, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return self::NAMES[$normalized] ?? strtoupper($normalized);
    }

    /**
     * Whether the language writes right to left.
     */
    public static function direction(string $code): string
    {
        return in_array(self::normalize($code), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr';
    }

    private static function normalize(string $code): string
    {
        return strtolower(substr(trim($code), 0, 2));
    }
}
