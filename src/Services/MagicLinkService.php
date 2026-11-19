<?php

declare(strict_types=1);

namespace Polaris\Services;

use Polaris\Support\Clock;
use Polaris\Repositories\MagicLinkRepository;
use Polaris\Support\Url;

/**
 * Creates single-use login links and optional safe post-login redirects.
 */
final class MagicLinkService
{
    public const TTL_LOGIN = 900;
    public const TTL_NOTIFICATION = 86400;
    public const TTL_DELETION = 3600;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private MagicLinkRepository $links,
        private array $settings,
    ) {
    }

    /** @param array<string,mixed> $user */
    public function create(
        array $user,
        ?string $redirectPath = null,
        int $ttlSeconds = self::TTL_LOGIN,
        ?string $ip = null,
        ?string $userAgent = null,
    ): string {
        // The redirect is checked before the token is stored: an unsafe path
        // must not leave a usable link behind.
        $safeRedirect = $redirectPath === null ? null : Url::safePath($redirectPath);

        if ($redirectPath !== null && $safeRedirect === null) {
            throw new \InvalidArgumentException('Invalid magic link redirect path.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = Clock::at(time() + max(60, $ttlSeconds));

        $this->links->create((int) $user['id'], hash('sha256', $token), $expiresAt, $ip, $userAgent);

        return $this->urlFor($token, $safeRedirect);
    }

    /**
     * The link, with the safe redirect the visitor lands on.
     *
     * @throws \InvalidArgumentException When the redirect path is not a safe one.
     */
    public function urlFor(string $token, ?string $redirectPath = null): string
    {
        $url = rtrim((string) $this->settings['url'], '/') . '/auth/' . $token;

        if ($redirectPath === null) {
            return $url;
        }

        $safe = Url::safePath($redirectPath);

        if ($safe === null) {
            throw new \InvalidArgumentException('Invalid magic link redirect path.');
        }

        return $url . '?next=' . rawurlencode($safe);
    }
}
