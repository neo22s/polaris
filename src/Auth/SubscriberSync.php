<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * Optional audience sync performed the first time an address is verified.
 *
 * Most applications do not run a newsletter. The default binding in the
 * container is a no-op implementation, so this interface never blocks the
 * installation of a site.
 */
interface SubscriberSync
{
    public function syncUser(string $email, ?string $locale): bool;
}
