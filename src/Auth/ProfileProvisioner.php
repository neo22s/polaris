<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * Creates the extra rows a new account needs.
 *
 * Optional: an application with no separate profile table binds
 * NullProfileProvisioner and the account row is all that gets created.
 */
interface ProfileProvisioner
{
    /**
     * Called once per new account, inside the account transaction.
     *
     * @param array<string,mixed> $fields Extra columns the caller supplied.
     */
    public function provision(int $userId, array $fields): void;
}
