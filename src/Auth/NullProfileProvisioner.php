<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * Does nothing. Bound by default on sites that have no profile table.
 */
final class NullProfileProvisioner implements ProfileProvisioner
{
    public function provision(int $userId, array $fields): void
    {
    }
}
