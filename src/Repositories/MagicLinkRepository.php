<?php

declare(strict_types=1);

namespace Polaris\Repositories;

use Polaris\Support\Database;

/**
 * Database access for `magic_links` (passwordless login tokens).
 */
final class MagicLinkRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ip, ?string $userAgent): int
    {
        return $this->db->insert('magic_links', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findValidByHash(string $tokenHash): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM magic_links
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now
             ORDER BY id DESC LIMIT 1',
            ['hash' => $tokenHash, 'now' => date('Y-m-d H:i:s')],
        );
    }

    public function markUsed(int $id): void
    {
        $this->db->execute(
            'UPDATE magic_links SET used_at = :now WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /**
     * Links that are still usable right now.
     *
     * Used by the admin dashboard to spot a flood of requests, and by the test
     * suite to prove an invalidation actually invalidated something.
     *
     * @return array<int,array<string,mixed>>
     */
    public function validNow(): array
    {
        return $this->db->select(
            'SELECT * FROM magic_links WHERE used_at IS NULL AND expires_at > :now ORDER BY id DESC',
            ['now' => date('Y-m-d H:i:s')],
        );
    }

    public function invalidateForUser(int $userId): void
    {
        $this->db->execute(
            'UPDATE magic_links SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'user_id' => $userId],
        );
    }
}
