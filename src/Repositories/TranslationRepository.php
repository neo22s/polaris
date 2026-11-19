<?php

declare(strict_types=1);

namespace Polaris\Repositories;

use Polaris\Support\Clock;
use Polaris\Support\Database;

/**
 * Database access for `translation_overrides`.
 *
 * Admin-edited translations are stored here and layered on top of the values
 * in resources/lang/*.php, so the language files stay untouched.
 */
final class TranslationRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Overrides for a single locale as key => value.
     *
     * @return array<string,string>
     */
    public function overridesFor(string $locale): array
    {
        $rows = $this->db->select(
            'SELECT translation_key, value FROM translation_overrides WHERE locale = :locale',
            ['locale' => $locale],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['translation_key']] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * All overrides grouped by locale: [locale => [key => value]].
     *
     * @return array<string,array<string,string>>
     */
    public function allGroupedByLocale(): array
    {
        $rows = $this->db->select('SELECT locale, translation_key, value FROM translation_overrides');

        $out = [];
        foreach ($rows as $row) {
            $out[$row['locale']][$row['translation_key']] = (string) $row['value'];
        }

        return $out;
    }

    public function deleteById(int $id): bool
    {
        $row = $this->db->selectOne('SELECT id FROM translation_overrides WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return false;
        }

        $this->db->execute('DELETE FROM translation_overrides WHERE id = :id', ['id' => $id]);

        return true;
    }

    public function set(string $locale, string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO translation_overrides (locale, translation_key, value, updated_at)
             VALUES (:locale, :key, :value, :updated_at)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [
                'locale' => $locale,
                'key' => $key,
                'value' => $value,
                'updated_at' => Clock::now(),
            ],
        );
    }

    public function delete(string $locale, string $key): void
    {
        $this->db->execute(
            'DELETE FROM translation_overrides WHERE locale = :locale AND translation_key = :key',
            ['locale' => $locale, 'key' => $key],
        );
    }

    public function count(): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM translation_overrides')['c'];
    }
}
