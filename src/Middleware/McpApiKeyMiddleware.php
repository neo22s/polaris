<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Api\ApiKeyMiddleware;
use Polaris\Repositories\RateLimitRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Compatibility alias for the MCP API-key middleware.
 *
 * The REST and MCP surfaces share one implementation; this class only keeps the
 * historical name so a site that mounted it keeps working. New code should use
 * `ApiKeyMiddleware` with the `mcp` mode.
 *
 * @deprecated Use ApiKeyMiddleware with mode 'mcp' instead.
 */
final class McpApiKeyMiddleware implements MiddlewareInterface
{
    private ApiKeyMiddleware $middleware;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings, RateLimitRepository $limiter)
    {
        $this->middleware = new ApiKeyMiddleware($settings, $limiter, 'mcp');
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        return $this->middleware->process($request, $handler);
    }
}
