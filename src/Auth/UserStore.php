<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * The user rows the authentication flow needs.
 *
 * The method list is deliberately narrow: a starter kit that grows a 40-method
 * user repository would force every application to implement all of it. The
 * application binds this interface to its own repository, which may expose many
 * more methods for its own features.
 *
 * @phpstan-type UserRow array<string,mixed>
 */
interface UserStore
{
    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array;

    /** @param array<string,mixed> $data */
    public function create(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void;

    public function markEmailVerified(int $id): void;

    public function promote(int $id): void;

    public function setStatus(int $id, string $status): void;
}
