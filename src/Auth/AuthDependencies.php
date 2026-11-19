<?php

declare(strict_types=1);

namespace Polaris\Auth;

use Polaris\Mail\MailerInterface;
use Polaris\Repositories\MagicLinkRepository;
use Polaris\Repositories\RateLimitRepository;
use Polaris\Services\MagicLinkService;
use Polaris\Support\Database;

/**
 * The collaborators AuthService needs, grouped behind one value.
 *
 * The legacy nine-argument constructor stays for sites that already call it;
 * new wiring passes this object instead, so adding a collaborator does not turn
 * every call site into a positional-argument puzzle.
 */
final class AuthDependencies
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        public readonly UserStore $users,
        public readonly MagicLinkRepository $magicLinks,
        public readonly MagicLinkService $linkService,
        public readonly MailerInterface $mail,
        public readonly SubscriberSync $newsletter,
        public readonly ProfileProvisioner $provisioner,
        public readonly Database $db,
        public readonly RateLimitRepository $limiter,
        public readonly array $settings,
    ) {
    }
}
