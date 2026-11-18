<?php

declare(strict_types=1);

namespace Polaris\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Throwable;

/**
 * Turns the registry into REST routes.
 *
 * One route per operation with an HTTP binding — no controller, no per-endpoint
 * glue. The handler is uniform: collect the arguments the schema declares, call
 * the operation, serialise the result. Anything else an endpoint needs (a
 * different status code, a redirect) is a sign it is not an API endpoint but a
 * page, and pages belong in a controller.
 *
 * Mounting is separate from `Kernel::boot` so a site decides whether it exposes
 * the API at all, and can attach middleware to it.
 */
final class RestRouter
{
    /**
     * @param App $app The Slim application.
     * @param Registry $registry Operations to expose.
     * @param string $prefix Route prefix, `/api/v1` by default.
     * @param array<int,string> $middleware PSR-15 middleware class names, applied per route.
     */
    public static function mount(
        App $app,
        Registry $registry,
        string $prefix = '/api/v1',
        array $middleware = [],
    ): void {
        $prefix = rtrim($prefix, '/');

        $app->group($prefix, function (RouteCollectorProxy $group) use ($registry, $prefix, $middleware): void {
            foreach ($registry->groups() as $apiGroup) {
                foreach ($apiGroup->restOperations() as $operation) {
                    $path = self::relativePath($operation->path, $prefix);

                    // Not a `static` closure: Slim binds a route callable to the
                    // container, and `Closure::bind` on a static closure returns
                    // null, which surfaces as "callable must be of type callable".
                    $handler = fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => self::handle(
                        $operation,
                        $request,
                        $response,
                    );

                    $route = $group->map([$operation->httpMethod], $path, $handler);

                    foreach ($middleware as $class) {
                        $route->add($class);
                    }
                }
            }
        });
    }

    /**
     * The operation path without the group prefix, because the group already
     * adds it. Authoring `/api/v1/users` reads better than `/users` when an
     * operation is documented, so both forms are accepted.
     */
    private static function relativePath(string $path, string $prefix): string
    {
        if ($path === $prefix) {
            return '/';
        }

        if (str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    private static function handle(
        Operation $operation,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        try {
            $result = $operation->call(self::arguments($operation, $request));

            $response->getBody()->write((string) json_encode(
                $result,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            return self::error($response, 400, $e->getMessage());
        } catch (Throwable $e) {
            error_log(sprintf('[Polaris] API call %s failed: %s', $operation->name, $e->getMessage()));

            return self::error($response, 500, 'Internal error');
        }
    }

    /**
     * Build the argument array from the binding and the schema.
     *
     * - A `{placeholder}` in the path comes from the route argument.
     * - A GET reads everything else from the query string.
     * - Anything else reads the parsed body, so JSON and form posts both work.
     *
     * Only declared properties are copied: an undeclared query parameter must
     * not reach a handler that never validated it.
     *
     * @return array<string,mixed>
     */
    private static function arguments(Operation $operation, ServerRequestInterface $request): array
    {
        $route = $request->getAttribute('__route__');
        $routeArguments = $route !== null && method_exists($route, 'getArguments') ? $route->getArguments() : [];
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $arguments = [];

        foreach (array_keys($operation->properties) as $name) {
            if (array_key_exists($name, $routeArguments)) {
                $arguments[$name] = $routeArguments[$name];
                continue;
            }

            if ($operation->httpMethod === 'GET') {
                if (array_key_exists($name, $query)) {
                    $arguments[$name] = self::cast($query[$name], $operation->properties[$name]);
                }

                continue;
            }

            if (array_key_exists($name, $body)) {
                $arguments[$name] = $body[$name];
            }
        }

        return $arguments;
    }

    /**
     * A query string is text; a schema says what it should be.
     *
     * Without this, `?limit=10` reaches a handler as "10" and `LIMIT :limit`
     * fails on a real server-side prepared statement.
     *
     * @param array<string,mixed> $schema
     */
    private static function cast(mixed $value, array $schema): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match ($schema['type'] ?? null) {
            'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
    }

    private static function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            ['error' => ['status' => $status, 'message' => $message]],
            JSON_UNESCAPED_SLASHES,
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
