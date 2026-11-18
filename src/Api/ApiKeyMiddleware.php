<?php

declare(strict_types=1);

namespace Polaris\Api;

use Polaris\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * API-key authentication shared by the REST and MCP surfaces.
 *
 * Keys are compared with `hash_equals`, which is constant time: a normal `===`
 * leaks the position of the first wrong byte and makes a key guessable one byte
 * at a time.
 *
 * Two modes, because the two surfaces differ:
 *
 * - REST sends `X-API-KEY` and gets a 401 with a JSON body.
 * - MCP sends `X-MCP-API-KEY`, keeps its JSON-RPC error envelope, and uses its
 *   own key so a REST key cannot reach the tool surface by accident.
 */
final class ApiKeyMiddleware implements MiddlewareInterface
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private array $settings,
        private RateLimiter $limiter,
        private string $mode = 'rest',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = $this->config();
        $expected = (string) ($config['api_key'] ?? '');

        if (($config['enabled'] ?? true) === false || strlen($expected) < 32) {
            return $this->error(-32000, $this->label() . ' is not enabled', 503);
        }

        $provided = (string) $request->getHeaderLine($this->header());

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->error(-32001, 'Invalid ' . $this->label() . ' key', 401);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        [$max, $window] = (array) ($config['rate_limit'] ?? [60, 60]);
        $key = $this->mode . ':' . hash('sha256', $expected) . ':' . $ip;

        if (!$this->limiter->attempt($key, (int) $max, (int) $window)) {
            return $this->error(-32002, 'Too many requests', 429);
        }

        if ($this->mode === 'mcp' && strtoupper($request->getMethod()) !== 'POST') {
            return $this->error(-32003, 'Method not allowed', 405);
        }

        return $handler->handle($request);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return $this->mode === 'mcp' ? ($this->settings['mcp'] ?? []) : ($this->settings['api'] ?? []);
    }

    private function header(): string
    {
        return $this->mode === 'mcp' ? 'X-MCP-API-KEY' : 'X-API-KEY';
    }

    private function label(): string
    {
        return $this->mode === 'mcp' ? 'MCP API' : 'API';
    }

    private function error(int $code, string $message, int $status): ResponseInterface
    {
        if ($this->mode === 'mcp') {
            $body = json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => $code, 'message' => $message],
            ]);
        } else {
            $body = json_encode([
                'error' => ['status' => $status, 'message' => $message],
            ]);
        }

        $response = new Response($status);
        $response->getBody()->write($body !== false ? $body : '{}');

        $response = $response->withHeader('Content-Type', 'application/json');

        if ($this->mode !== 'mcp') {
            $response = $response->withHeader('WWW-Authenticate', 'ApiKey');
        }

        return $response;
    }
}
