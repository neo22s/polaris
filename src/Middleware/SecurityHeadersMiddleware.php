<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security response headers (CSP, HSTS, nosniff, etc.).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self' https://vilaestudio.com; "
        . "frame-src https://www.youtube.com https://www.youtube-nocookie.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://maxcdn.bootstrapcdn.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://maxcdn.bootstrapcdn.com; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://www.googletagmanager.com; "
        . "connect-src 'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Content-Security-Policy', self::CSP);

        if ($this->isSecure($request)) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();

        return ($server['HTTPS'] ?? '') === 'on'
            || ($request->getHeaderLine('X-Forwarded-Proto') === 'https');
    }
}
