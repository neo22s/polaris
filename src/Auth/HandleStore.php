<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * A user store that also has public handles.
 *
 * Kept separate from UserStore because a handle is optional: a small internal
 * tool can authenticate users by email alone, while a public site needs the
 * unique, URL-safe handle that RegistrationValidator checks.
 */
interface HandleStore extends UserStore
{
    public function handleExists(string $handle, ?int $exceptUserId = null): bool;
}
