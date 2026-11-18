<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Middleware\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The security headers are the site's baseline defence, so the defaults matter
 * more than the configuration: a fresh install must not ship an open CSP.
 */
final class SecurityHeadersTest extends TestCase
{
    public function testTheDefaultCspDoesNotAllowInlineScriptsOrExternalHosts(): void
    {
        $response = $this->dispatch(new SecurityHeadersMiddleware());
        $csp = $response->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('http', $csp);
    }

    public function testASiteCanReplaceThePolicyWhenItNeedsExternalHosts(): void
    {
        $response = $this->dispatch(new SecurityHeadersMiddleware([
            "default-src 'self'",
            "script-src 'self' https://cdn.example.com",
        ]));

        $csp = $response->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' https://cdn.example.com", $csp);
    }

    public function testForwardedProtoIsIgnoredWhenNoProxyIsTrusted(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->dispatch(new SecurityHeadersMiddleware(), $request);

        $this->assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testForwardedProtoIsHonouredForATrustedProxy(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->dispatch(new SecurityHeadersMiddleware([], ['127.0.0.1']), $request);

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    private function dispatch(SecurityHeadersMiddleware $middleware, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $request ??= (new ServerRequestFactory())->createServerRequest('GET', '/');

        return $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        });
    }
}
