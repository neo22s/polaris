<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Support\TrustedProxy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security response headers (CSP, HSTS, nosniff, etc.).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int,string> $csp Extra directives appended after `default-src`.
     * @param array<int,string> $trustedProxies Peers allowed to set X-Forwarded-Proto.
     */
    public function __construct(
        private array $csp = [],
        private array $trustedProxies = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Content-Security-Policy', $this->csp());

        if ((new TrustedProxy($this->trustedProxies))->isSecure($request)) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * The default CSP is strict: Polaris itself ships no inline scripts, no
     * eval, and no external resources. A site that needs CDNs or inline JS
     * can configure them; the default must not be permissive.
     */
    private function csp(): string
    {
        if ($this->csp !== []) {
            return implode('; ', $this->csp);
        }

        return "default-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "object-src 'none'; "
            . "frame-ancestors 'self'; "
            . "img-src 'self' data: blob:; "
            . "font-src 'self'; "
            // Inline styles are allowed because templates and the generated
            // docs use style attributes; CSS cannot execute JavaScript. Scripts
            // stay strict, which is the directive that matters.
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; "
            . "connect-src 'self'";
    }

}
