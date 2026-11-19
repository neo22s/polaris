<?php

declare(strict_types=1);

namespace Polaris\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * One answer to "is this request secure?" — used for both cookies and HSTS.
 *
 * A forwarded `https` is only believed when the immediate peer is in the
 * explicit trusted-proxy list; otherwise a client could set the header itself
 * and ask for a Secure cookie or HSTS on a plain HTTP site.
 */
final class TrustedProxy
{
    /** @param array<int,string> $trustedProxies */
    public function __construct(private array $trustedProxies = [])
    {
    }

    public function isSecure(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();
        $https = $server['HTTPS'] ?? '';

        if ($https === 'on' || $https === '1' || $https === 1 || $https === true) {
            return true;
        }

        if ($this->trustedProxies === []) {
            return false;
        }

        $peer = (string) ($server['REMOTE_ADDR'] ?? 'unknown');

        if (!in_array($peer, $this->trustedProxies, true)) {
            return false;
        }

        return strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
