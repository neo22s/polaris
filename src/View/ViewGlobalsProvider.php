<?php

declare(strict_types=1);

namespace Polaris\View;

/**
 * Adds application-specific Twig globals.
 *
 * The kernel exposes the globals every site needs (current user, locale,
 * CSRF token, flash messages). Anything else — a gender list, a cart, a
 * navigation tree — is the application's business, and it registers a
 * provider instead of the kernel growing domain knowledge.
 */
interface ViewGlobalsProvider
{
    /** @return array<string,mixed> */
    public function globals(): array;
}
