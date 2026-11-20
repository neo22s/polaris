<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Support\Csrf;
use Polaris\Support\Flash;
use Polaris\Support\Translator;
use Polaris\Support\Url;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Rejects state-changing requests without a valid CSRF token.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private array $settings,
        private Flash $flash,
        private ResponseFactory $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // An API request authenticates with a key in a header, never with a
        // cookie. A browser cannot be made to send that header cross-origin, so
        // there is nothing for CSRF to protect: the attack CSRF defends against
        // is the browser attaching the session automatically.
        //
        // Two cases are exempt:
        //
        // 1. A valid key in the header, on any route. A proxy that injects an
        //    arbitrary header must not switch CSRF off, so the value has to
        //    match the configured key.
        // 2. A path under the API prefix. Those routes authenticate per route
        //    with an API key, and the middleware that rejects a missing or
        //    invalid key has to produce the 401 JSON envelope. Running CSRF
        //    first would turn "wrong key" into an HTML redirect, which is not
        //    something an API client can act on.
        if ($this->hasValidApiKey($request) || $this->isApiPath($request)) {
            return $handler->handle($request);
        }

        $method = strtoupper($request->getMethod());

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body['_token'] ?? null) : null;
            $token ??= $request->getHeaderLine('X-CSRF-Token');

            if (!Csrf::validate(is_string($token) ? $token : null)) {
                $this->flash->error(Translator::t('flash.session_expired'));

                // Send the visitor back where they came from with a message.
                // The status has to stay a redirect: browsers only follow a
                // Location header on a 3xx response.
                $referer = $request->getHeaderLine('Referer');
                $target = $referer !== '' ? parse_url($referer, PHP_URL_PATH) : '/';
                $target = Url::safePath($target) ?? '/';

                $response = $this->responses->createResponse(302);

                return $response->withHeader('Location', $target);
            }
        }

        return $handler->handle($request);
    }

    /**
     * API routes authenticate with a header and answer with JSON, including
     * when the key is wrong. CSRF never applies to them: there is no cookie to
     * protect, and the key middleware is the one that must answer.
     */
    private function isApiPath(ServerRequestInterface $request): bool
    {
        $path = strtolower($request->getUri()->getPath());

        return $path === '/api' || str_starts_with($path, '/api/');
    }

    /**
     * A key is a shared secret, so a header carrying the right one proves the
     * caller is not a browser being tricked. The same length rule as
     * ApiKeyMiddleware applies: a short placeholder key cannot authenticate,
     * so it must not disable CSRF either.
     */
    private function hasValidApiKey(ServerRequestInterface $request): bool
    {
        $keys = [
            'X-API-KEY' => $this->settings['api']['api_key'] ?? '',
            'X-MCP-API-KEY' => $this->settings['mcp']['api_key'] ?? '',
        ];

        foreach ($keys as $header => $expected) {
            $provided = $request->getHeaderLine($header);

            if ($provided === '' || !is_string($expected) || strlen($expected) < 32) {
                continue;
            }

            if (hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }
}
