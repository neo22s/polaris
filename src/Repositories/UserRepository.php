<?php

declare(strict_types=1);

namespace Polaris\Repositories;

use Polaris\Support\Clock;
use Polaris\Auth\HandleStore;
use Polaris\Auth\NewsletterAudience;
use Polaris\Support\Database;

/**
 * Database access for the `users` table.
 *
 * Only the generic columns live here: email, role, handle, status, locale and
 * verification timestamps. Anything an application adds (profiles, billing,
 * workspaces) belongs to its own repository, which can extend this one.
 *
 * Not final on purpose: an application often extends it, and a test overrides
 * a method to simulate a failure.
 */
class UserRepository implements HandleStore, NewsletterAudience
{
    public function __construct(protected Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE email = :email', ['email' => $email]);
    }

    /** @return array<string,mixed>|null */
    public function findByHandle(string $handle): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE handle = :handle', ['handle' => $handle]);
    }

    public function handleExists(string $handle, ?int $exceptUserId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS c FROM users WHERE handle = :handle';
        $params = ['handle' => $handle];

        if ($exceptUserId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptUserId;
        }

        return (int) ($this->db->selectOne($sql, $params)['c'] ?? 0) > 0;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('users', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = Clock::now();
        $this->db->update('users', $data, 'id = :where_id', ['where_id' => $id]);
    }

    public function markEmailVerified(int $id): void
    {
        $this->update($id, ['email_verified_at' => Clock::now()]);
    }

    public function promote(int $id): void
    {
        $this->update($id, ['role' => 'admin']);
    }

    public function demote(int $id): void
    {
        $this->update($id, ['role' => 'member']);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->update($id, ['status' => $status]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }

    public function countAll(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    public function firstAdminId(): ?int
    {
        $row = $this->db->selectOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");

        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<int,array<string,mixed>> */
    public function admins(): array
    {
        return $this->db->select(
            "SELECT id, email, handle, locale FROM users
             WHERE role = 'admin' AND status = 'active'
             ORDER BY id ASC",
        );
    }

    /**
     * One page of active accounts, newest first.
     *
     * The API uses this with `Pagination`: it asks for one row more than the
     * page, so "is there more" costs nothing extra.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activePage(int $offset, int $limit): array
    {
        return $this->db->select(
            'SELECT * FROM users WHERE status = :status ORDER BY id DESC LIMIT :limit OFFSET :offset',
            ['status' => 'active', 'limit' => max(1, $limit), 'offset' => max(0, $offset)],
        );
    }

    /**
     * Active accounts with an address, for the newsletter sync.
     *
     * @return array<int,array{email:string,locale:?string}>
     */
    public function activeSubscribers(): array
    {
        /** @var array<int,array{email:string,locale:?string}> $rows */
        $rows = $this->db->select(
            'SELECT email, locale FROM users
             WHERE status = :status AND email IS NOT NULL AND email <> :empty
             ORDER BY id ASC',
            ['status' => 'active', 'empty' => ''],
        );

        return $rows;
    }
}
