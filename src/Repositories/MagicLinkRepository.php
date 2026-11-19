<?php

declare(strict_types=1);

namespace Polaris\Repositories;

use Polaris\Support\Clock;
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
            'created_at' => Clock::now(),
        ]);
    }

    /**
     * Consume a still-valid token and return the matching link row.
     *
     * The UPDATE is done with a WHERE that only matches an unused, unexpired
     * row. MySQL reports how many rows changed, so two concurrent requests
     * carrying the same token cannot both succeed: one wins the race, the
     * other sees 0 affected rows and gets null.
     */
    public function consume(string $tokenHash): ?array
    {
        $now = Clock::now();

        $link = $this->db->selectOne(
            'SELECT * FROM magic_links
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now
             ORDER BY id DESC LIMIT 1',
            ['hash' => $tokenHash, 'now' => $now],
        );

        if ($link === null) {
            return null;
        }

        // Native prepares reject a repeated named placeholders, so the current
        // time is bound twice: once for the write and once for the guard.
        $affected = $this->db->execute(
            'UPDATE magic_links
             SET used_at = :now
             WHERE id = :id AND used_at IS NULL AND expires_at > :now_again',
            ['now' => $now, 'id' => (int) $link['id'], 'now_again' => $now],
        );

        return $affected > 0 ? $link : null;
    }

    /**
     * The newest unused, unexpired link for a hash, without consuming it.
     *
     * Kept for callers that only need to inspect a token. Authentication goes
     * through consume(), which is atomic.
     */
    public function findValidByHash(string $tokenHash): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM magic_links
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now
             ORDER BY id DESC LIMIT 1',
            ['hash' => $tokenHash, 'now' => Clock::now()],
        );
    }

    public function markUsed(int $id): void
    {
        $this->db->execute(
            'UPDATE magic_links SET used_at = :now WHERE id = :id',
            ['now' => Clock::now(), 'id' => $id],
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
            ['now' => Clock::now()],
        );
    }

    public function invalidateForUser(int $userId): void
    {
        $this->db->execute(
            'UPDATE magic_links SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL',
            ['now' => Clock::now(), 'user_id' => $userId],
        );
    }
}
