<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Middleware\CsrfMiddleware;
use Polaris\Support\Csrf;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * A header is not a credential just because it exists. The CSRF exemption is
 * keyed on a *valid* API key, so a proxy injecting a header cannot switch the
 * protection off for every form on the site.
 */
final class CsrfMiddlewareTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $previousSession = null;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->previousSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->previousSession;
        }
    }

    public function testAValidApiKeyExemptsTheRequest(): void
    {
        $settings = ['api' => ['api_key' => str_repeat('a', 64)], 'mcp' => ['api_key' => '']];
        $request = $this->request()->withHeader('X-API-KEY', str_repeat('a', 64));

        $this->assertTrue($this->passes($settings, $request));
    }

    public function testAWrongApiKeyDoesNotExemptTheRequest(): void
    {
        $settings = ['api' => ['api_key' => str_repeat('a', 64)], 'mcp' => ['api_key' => '']];
        $request = $this->request()->withHeader('X-API-KEY', str_repeat('b', 64));

        $this->assertFalse($this->passes($settings, $request));
    }

    public function testAPlaceholderHeaderValueDoesNotExemptTheRequest(): void
    {
        $settings = ['api' => ['api_key' => str_repeat('a', 64)], 'mcp' => ['api_key' => '']];
        $request = $this->request()->withHeader('X-API-KEY', 'anything');

        $this->assertFalse($this->passes($settings, $request));
    }

    public function testTheMcpKeyIsRecognisedToo(): void
    {
        $settings = ['api' => ['api_key' => str_repeat('a', 64)], 'mcp' => ['api_key' => str_repeat('c', 64)]];
        $request = $this->request()->withHeader('X-MCP-API-KEY', str_repeat('c', 64));

        $this->assertTrue($this->passes($settings, $request));
    }

    public function testApiPathsReachTheirKeyMiddlewareInsteadOfCsrf(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/users');

        $this->assertTrue(
            $this->passes(['api' => [], 'mcp' => []], $request),
            'An API route must answer 401 JSON, not a CSRF redirect, when the key is missing.',
        );
    }

    public function testANonApiPathWithoutAKeyIsStillProtected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/delete');

        $this->assertFalse($this->passes(['api' => [], 'mcp' => []], $request));
    }

    public function testAValidCsrfTokenStillPassesWithoutAKey(): void
    {
        $_SESSION['_csrf_token'] = 'the-token';
        $request = $this->request()->withParsedBody(['_token' => 'the-token']);

        $this->assertTrue($this->passes(['api' => [], 'mcp' => []], $request));
    }

    /** @param array<string,mixed> $settings */
    private function passes(array $settings, ServerRequestInterface $request): bool
    {
        $middleware = new CsrfMiddleware($settings, new \Polaris\Support\Flash(), new ResponseFactory());
        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response();
            }
        };

        $middleware->process($request, $handler);

        return $handler->called;
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/anything');
    }
}
