<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * Does nothing. Bound by default when the application runs no newsletter.
 */
final class NullSubscriberSync implements SubscriberSync
{
    public function syncUser(string $email, ?string $locale): bool
    {
        return true;
    }
}
