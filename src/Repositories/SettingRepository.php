<?php

declare(strict_types=1);

namespace Polaris\Repositories;

use Polaris\Support\Clock;
use Polaris\Support\Database;

/**
 * Database access for the `settings` key/value table.
 */
final class SettingRepository
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (setting_key, value, updated_at) VALUES (:key, :value, :updated_at)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            ['key' => $key, 'value' => $value, 'updated_at' => Clock::now()],
        );

        $this->cache = null;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->db->select('SELECT setting_key, value FROM settings');
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['value'];
        }

        return $this->cache = $out;
    }
}
