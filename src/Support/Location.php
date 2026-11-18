<?php

declare(strict_types=1);

namespace Polaris\Support;

use Collator;
use Locale;

/**
 * Country and city data with localized country names (via intl).
 */
final class Location
{
    /** @var array<string,string>|null */
    private static ?array $countries = null;

    /** @var array<string,array<int,string>>|null */
    private static ?array $cities = null;

    /** @var array<string,array<string,string>> */
    private static array $localizedCountries = [];

    /** @var array<string,string> */
    private static array $countryNames = [];

    /**
     * All countries as code => localized name, sorted by name for the current locale.
     *
     * @return array<string,string>
     */
    public static function countries(?string $locale = null): array
    {
        $locale = $locale ?? Translator::instance()->getLocale();

        if (isset(self::$localizedCountries[$locale])) {
            return self::$localizedCountries[$locale];
        }

        $names = [];
        foreach (self::rawCountries() as $code => $fallback) {
            $names[$code] = self::countryName($code, $locale) ?? $fallback;
        }

        if (class_exists(Collator::class)) {
            $collator = new Collator($locale);
            $collator->asort($names);
        } else {
            asort($names);
        }

        return self::$localizedCountries[$locale] = $names;
    }

    public static function countryName(?string $code, ?string $locale = null): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $code = strtoupper($code);
        $raw = self::rawCountries();

        if (!isset($raw[$code])) {
            return null;
        }

        $locale = $locale ?? Translator::instance()->getLocale();
        $cacheKey = $locale . ':' . $code;

        if (isset(self::$countryNames[$cacheKey])) {
            return self::$countryNames[$cacheKey];
        }

        $name = $raw[$code];

        if (class_exists(Locale::class)) {
            $display = Locale::getDisplayRegion('-' . $code, $locale);
            if (is_string($display) && $display !== '') {
                $name = $display;
            }
        }

        return self::$countryNames[$cacheKey] = $name;
    }

    public static function isValidCountry(?string $code): bool
    {
        return $code !== null && $code !== '' && isset(self::rawCountries()[strtoupper($code)]);
    }

    /** @return array<int,string> */
    public static function cities(?string $code): array
    {
        if ($code === null || $code === '') {
            return [];
        }

        return self::rawCities()[strtoupper($code)] ?? [];
    }

    /** @return array<string,string> */
    private static function rawCountries(): array
    {
        if (self::$countries === null) {
            self::$countries = require dirname(__DIR__, 2) . '/resources/data/countries.php';
        }

        return self::$countries;
    }

    /** @return array<string,array<int,string>> */
    private static function rawCities(): array
    {
        if (self::$cities === null) {
            self::$cities = require dirname(__DIR__, 2) . '/resources/data/cities.php';
        }

        return self::$cities;
    }
}
