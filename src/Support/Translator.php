<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Small array-based i18n layer: locale detection, lookup and a fallback locale.
 *
 * The supported locale list is injected, not hardcoded: a site declares the
 * languages it ships (settings.paths.locales or the `locales` setting), and the
 * kernel only enforces that every lookup resolves and every language file has
 * the same keys.
 */
final class Translator
{
    /** Fallback used when a site does not declare its languages. */
    public const DEFAULT_LOCALES = ['en'];

    public const DEFAULT = 'en';

    private static ?Translator $instance = null;

    /** @var array<string,string> */
    private array $messages = [];

    /** @var array<string,string>|null Fallback strings, loaded only if a key is missing. */
    private ?array $fallback = null;

    /** @var array<string,array<string,string>> Parsed language files. */
    private static array $files = [];

    private string $locale;

    private bool $loaded = false;

    /** @var array<int,string> Lowercase locale codes the site supports. */
    private array $supported;

    /** @var array<int,string> Directories searched for language files, highest priority first. */
    private array $langDirs;

    /**
     * @param string|array<int,string> $langDir One directory, or a list ordered
     *        from highest to lowest priority. An application passes its own
     *        directory first and the kernel directory last, so a site can
     *        translate or reword a kernel string without editing the kernel.
     * @param (\\Closure(string):array<string,string>)|null $overrides
     *        Optional loader returning admin overrides for a locale (merged on
     *        top of the language files).
     * @param array<int,string> $supported Locale codes the site ships. Defaults
     *        to English only, which keeps a fresh install honest: no language
     *        is offered until it exists.
     */
    public function __construct(
        string|array $langDir,
        private string $default = self::DEFAULT,
        private ?\Closure $overrides = null,
        array $supported = self::DEFAULT_LOCALES,
    ) {
        $this->langDirs = is_array($langDir) ? array_values($langDir) : [$langDir];
        $this->supported = self::normalizeLocales($supported);

        if (!in_array($default, $this->supported, true)) {
            // The fallback must be one of the shipped languages, otherwise a
            // missing key would resolve to an empty string.
            $this->default = $this->supported[0] ?? self::DEFAULT;
        }

        $this->locale = $this->default;
        // Nothing is read here: setLocale() loads right after construction in
        // every request, and reading in the constructor as well made that a
        // second file and a second overrides query.
        self::$instance = $this;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(dirname(__DIR__, 2) . '/resources/lang');
        }

        return self::$instance;
    }

    /** @param array<string,string|int> $params */
    public static function t(string $key, array $params = []): string
    {
        return self::instance()->trans($key, $params);
    }

    /** @return array<int,string> */
    public static function supported(): array
    {
        return self::instance()->supportedLocales();
    }

    public static function isSupported(?string $locale): bool
    {
        return self::instance()->supports($locale);
    }

    /** @return array<int,string> */
    public function supportedLocales(): array
    {
        return $this->supported;
    }

    public function supports(?string $locale): bool
    {
        return is_string($locale) && in_array(strtolower($locale), $this->supported, true);
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $this->supports($locale) ? strtolower($locale) : $this->default;
        $this->messages = $this->load($this->locale);
        $this->loaded = true;
    }

    /**
     * Run a callback with a temporary locale and always restore the previous one.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withLocale(string $locale, callable $callback): mixed
    {
        $previous = $this->locale;
        $this->setLocale($locale);

        try {
            return $callback();
        } finally {
            $this->setLocale($previous);
        }
    }

    /** Load the current locale the first time a string is actually asked for. */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->messages = $this->load($this->locale);
        $this->loaded = true;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** @param array<string,string|int> $params */
    public function trans(string $key, array $params = []): string
    {
        // The fallback is only read when a key is actually missing, so a
        // non-default visitor never pays for parsing the fallback file.
        $this->ensureLoaded();

        $value = $this->messages[$key] ?? null;

        if ($value === null) {
            if ($this->fallback === null) {
                $this->fallback = $this->loadFile($this->default);
            }

            $value = $this->fallback[$key] ?? $key;
        }

        foreach ($params as $name => $replacement) {
            // A word boundary, so a parameter called "a" does not replace the
            // ":a" inside ":admin".
            $value = (string) preg_replace(
                '/:' . preg_quote((string) $name, '/') . '\b/',
                (string) $replacement,
                $value,
            );
        }

        return $value;
    }

    public function has(string $key): bool
    {
        if (isset($this->messages[$key])) {
            return true;
        }

        if ($this->fallback === null) {
            $this->fallback = $this->loadFile($this->default);
        }

        return isset($this->fallback[$key]);
    }

    /**
     * Resolve the best locale from the user preference, session choice and browser header.
     *
     * A language the site does not ship is never selected, so a site with only
     * English does not offer Spanish just because the browser asks for it.
     */
    public static function detect(?string $userLocale, ?string $sessionLocale, ?string $acceptLanguage): string
    {
        $translator = self::instance();

        if ($translator->supports($userLocale)) {
            return (string) strtolower((string) $userLocale);
        }

        if ($translator->supports($sessionLocale)) {
            return (string) strtolower((string) $sessionLocale);
        }

        foreach (self::parseAcceptLanguage($acceptLanguage) as $candidate) {
            if ($translator->supports($candidate)) {
                return $candidate;
            }
        }

        return $translator->default;
    }

    /**
     * The languages the browser asked for, best first.
     *
     * The quality values are honoured, and a language the browser explicitly
     * refuses (q=0) is left out.
     *
     * @return array<int,string>
     */
    private static function parseAcceptLanguage(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));

            if ($tag === '') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($bits, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            $candidates[$tag] = max($candidates[$tag] ?? 0.0, $quality);
        }

        arsort($candidates);

        $locales = [];

        foreach (array_keys($candidates) as $tag) {
            $locales[] = substr($tag, 0, 2);
        }

        return $locales;
    }

    /**
     * Merge the language file with the admin overrides for that locale. This is
     * the only place a file is read and the database is asked, and it runs once
     * per locale per request (setLocale), not once per constructor as well.
     *
     * @return array<string,string>
     */
    private function load(string $locale): array
    {
        $messages = $this->loadFile($locale);

        // Layer admin overrides (from the database) on top of the file values.
        if (is_callable($this->overrides)) {
            $overrides = ($this->overrides)($locale);
            if (is_array($overrides) && $overrides !== []) {
                $messages = array_merge($messages, $overrides);
            }
        }

        return $messages;
    }

    /**
     * The raw language file for a locale, without the admin overrides.
     *
     * Used by the translation editor to know which keys exist and what the
     * shipped default is, so an override that matches the file can be removed.
     *
     * @return array<string,string>
     */
    public function fileDefaults(string $locale): array
    {
        return $this->loadFile($locale);
    }

    /**
     * A parsed language file. Shared across instances in the same request so the
     * fallback never re-reads what the current locale already loaded.
     *
     * @return array<string,string>
     */
    private function loadFile(string $locale): array
    {
        // The directory list is part of the key: two translators with a
        // different language directory must not share their files.
        $key = implode('|', $this->langDirs) . '|' . $locale;

        if (isset(self::$files[$key])) {
            return self::$files[$key];
        }

        $messages = [];

        // Lowest priority first, so the highest priority ends up on top.
        foreach (array_reverse($this->langDirs) as $directory) {
            $file = rtrim($directory, '/') . '/' . $locale . '.php';

            if (is_file($file)) {
                $messages = array_merge($messages, (array) require $file);
            }
        }

        return self::$files[$key] = $messages;
    }

    /**
     * @param array<int,string> $locales
     * @return array<int,string>
     */
    private static function normalizeLocales(array $locales): array
    {
        $normalized = [];

        foreach ($locales as $locale) {
            $locale = strtolower(trim((string) $locale));

            if ($locale !== '' && !in_array($locale, $normalized, true)) {
                $normalized[] = $locale;
            }
        }

        return $normalized === [] ? self::DEFAULT_LOCALES : $normalized;
    }
}
