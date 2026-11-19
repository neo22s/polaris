<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * The rule that promotes an account listed in `admin_emails`.
 *
 * Kept apart from authentication: promotion is a site policy, and a login is
 * only one of the places that has to apply it.
 */
final class AdminPromotion
{
    /** @param array<int,string> $adminEmails */
    public function __construct(
        private UserStore $users,
        private array $adminEmails = [],
    ) {
    }

    public function shouldBeAdmin(string $email): bool
    {
        $allow = array_map(static fn (string $email): string => strtolower(trim($email)), $this->adminEmails);

        return in_array(strtolower($email), $allow, true);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function maybePromote(array $user): array
    {
        if ($user['role'] !== 'admin' && $this->shouldBeAdmin((string) ($user['email'] ?? ''))) {
            $this->users->promote((int) $user['id']);
            $user['role'] = 'admin';
        }

        return $user;
    }
}
