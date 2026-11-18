<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Authenticates and rate-limits requests to the MCP endpoint.
 */
final class McpApiKeyMiddleware implements MiddlewareInterface
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private array $settings,
        private RateLimiter $limiter,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $config = $this->settings['mcp'] ?? [];
        $expected = (string) ($config['api_key'] ?? '');

        if (($config['enabled'] ?? true) === false || strlen($expected) < 32) {
            return $this->error(-32000, 'MCP is not enabled', 503);
        }

        $provided = (string) $request->getHeaderLine('X-MCP-API-KEY');

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->error(-32001, 'Invalid MCP API key', 401);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $bucket = 'mcp:' . hash('sha256', $expected) . ':' . $ip;
        $limit = max(1, (int) ($config['rate_limit'][0] ?? 60));
        $window = max(1, (int) ($config['rate_limit'][1] ?? 60));

        if (!$this->limiter->attempt($bucket, $limit, $window)) {
            return $this->error(-32002, 'Too many requests', 429);
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->error(-32003, 'Method not allowed', 405);
        }

        return $handler->handle($request);
    }

    private function error(int $code, string $message, int $status): Response
    {
        return $this->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status): Response
    {
        $response = new SlimResponse($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
