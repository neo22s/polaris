<?php

declare(strict_types=1);

namespace Polaris\Services;

use Polaris\Repositories\TranslationRepository;
use Polaris\Support\Translator;

/**
 * Validated writes for the admin translation overrides.
 *
 * Overrides are layered on top of the language files, so a key that does not
 * exist in a language file would be dead weight, and an unexpected locale would
 * never be rendered. Both the admin editor and the MCP adapter go through this
 * service so neither can write something the other would reject.
 */
final class TranslationService
{
    /** Longest override accepted; the admin editor edits one line at a time. */
    private const MAX_VALUE_LENGTH = 2000;

    public function __construct(
        private TranslationRepository $translations,
        private Translator $translator,
    ) {
    }

    /**
     * The shipped values of a language file, without any override.
     *
     * @return array<string,string>
     */
    public function fileDefaults(string $locale): array
    {
        return $this->translator->fileDefaults($locale);
    }

    /**
     * Whether a key exists in the given language file.
     */
    public function hasKey(string $locale, string $key): bool
    {
        return array_key_exists($key, $this->fileDefaults($locale));
    }

    /**
     * Check an override without writing it.
     *
     * @throws \InvalidArgumentException When the locale or key is unknown, or the value is too long.
     */
    public function assertValidOverride(string $locale, string $key, string $value): void
    {
        $this->assertKnownKey($locale, $key);

        if (mb_strlen(trim($value)) > self::MAX_VALUE_LENGTH) {
            throw new \InvalidArgumentException(Translator::t('validation.translation_value_too_long', [
                'max' => self::MAX_VALUE_LENGTH,
            ]));
        }
    }

    /**
     * Store an override for a locale/key pair.
     *
     * @throws \InvalidArgumentException When the locale or key is unknown, or the value is too long.
     */
    public function setOverride(string $locale, string $key, string $value): void
    {
        $this->assertValidOverride($locale, $key, $value);

        $this->translations->set($locale, $key, trim($value));
    }

    /**
     * Remove the override for a locale/key pair.
     *
     * @throws \InvalidArgumentException When the locale or key is unknown.
     */
    public function deleteOverride(string $locale, string $key): void
    {
        $this->assertKnownKey($locale, $key);

        $this->translations->delete($locale, $key);
    }

    /**
     * Remove an override by its row id.
     *
     * @throws \InvalidArgumentException When no override has that id.
     */
    public function deleteOverrideById(int $id): void
    {
        if ($id <= 0 || !$this->translations->deleteById($id)) {
            throw new \InvalidArgumentException(Translator::t('validation.translation_override_not_found'));
        }
    }

    /**
     * The rows of the translation editor.
     *
     * Every key of every language file, with the shipped value, the override
     * and the value that is actually shown.
     *
     * @return array<int,array{key:string,cells:array<string,array{file:string,override:?string,effective:string}>}>
     */
    public function listRows(string $search = '', string $group = ''): array
    {
        $locales = $this->translator->supportedLocales();
        $fileDefaults = [];
        $overrides = $this->translations->allGroupedByLocale();

        foreach ($locales as $locale) {
            $fileDefaults[$locale] = $this->fileDefaults($locale);
        }

        $allKeys = [];
        foreach ($locales as $locale) {
            foreach (array_keys($fileDefaults[$locale]) as $key) {
                $allKeys[$key] = true;
            }
        }

        $allKeys = array_keys($allKeys);
        sort($allKeys);

        $keys = $allKeys;

        if ($group !== '') {
            $keys = array_filter($keys, static fn (string $key): bool => str_starts_with($key, $group . '.'));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $keys = array_filter($keys, static fn (string $key): bool => str_contains(mb_strtolower($key), $needle));
        }

        $rows = [];

        foreach ($keys as $key) {
            $cells = [];

            foreach ($locales as $locale) {
                $file = (string) ($fileDefaults[$locale][$key] ?? '');
                $override = $overrides[$locale][$key] ?? null;

                $cells[$locale] = [
                    'file' => $file,
                    'override' => $override,
                    'effective' => $override ?? $file,
                ];
            }

            $rows[] = ['key' => $key, 'cells' => $cells];
        }

        return $rows;
    }

    /**
     * The groups the keys belong to, for the filter dropdown.
     *
     * @return array<int,string>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->translator->supportedLocales() as $locale) {
            foreach (array_keys($this->fileDefaults($locale)) as $key) {
                $groups[explode('.', $key, 2)[0]] = true;
            }
        }

        $groups = array_keys($groups);
        sort($groups);

        return $groups;
    }

    /**
     * How many overrides are stored.
     */
    public function count(): int
    {
        return $this->translations->count();
    }

    /**
     * Apply the overrides the editor submitted.
     *
     * A field that was left empty, or that still holds the shipped value,
     * removes its override. Everything is checked before anything is written,
     * so a bad value cannot leave a half applied save behind.
     *
     * @param array<string,mixed> $values locale => key => value
     *
     * @throws \InvalidArgumentException When a value is not acceptable.
     */
    public function saveOverrides(array $values): void
    {
        $changes = [];

        foreach ($this->translator->supportedLocales() as $locale) {
            if (!isset($values[$locale]) || !is_array($values[$locale])) {
                continue;
            }

            foreach ($values[$locale] as $key => $value) {
                $key = (string) $key;
                $value = trim((string) $value);

                // Keys that do not exist in the language file are ignored.
                if (!$this->hasKey($locale, $key)) {
                    continue;
                }

                $changes[] = [
                    'locale' => $locale,
                    'key' => $key,
                    'value' => $value,
                    'default' => $this->fileDefaults($locale)[$key] ?? '',
                ];
            }
        }

        foreach ($changes as $change) {
            $this->assertValidOverride($change['locale'], $change['key'], $change['value']);
        }

        foreach ($changes as $change) {
            if ($change['value'] === '' || $change['value'] === $change['default']) {
                $this->deleteOverride($change['locale'], $change['key']);
            } else {
                $this->setOverride($change['locale'], $change['key'], $change['value']);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertKnownKey(string $locale, string $key): void
    {
        if (!Translator::isSupported($locale)) {
            throw new \InvalidArgumentException(Translator::t('validation.locale_unsupported', [
                'locale' => $locale,
            ]));
        }

        if (!$this->hasKey($locale, $key)) {
            throw new \InvalidArgumentException(Translator::t('validation.translation_key_unknown', [
                'key' => $key,
            ]));
        }
    }
}
