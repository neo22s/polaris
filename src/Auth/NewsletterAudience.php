<?php

declare(strict_types=1);

namespace Polaris\Auth;

/**
 * Synchronises subscriber lists with an email provider (for example Acumbamail).
 *
 * The kernel ships the provider client and the sync loop, but not the query
 * that decides which accounts to subscribe: that depends on the application's
 * own columns. An application binds this interface to a repository of its own.
 */
interface NewsletterAudience
{
    /**
     * Active accounts with a usable address.
     *
     * @return array<int,array{email:string,locale:?string}>
     */
    public function activeSubscribers(): array;
}
