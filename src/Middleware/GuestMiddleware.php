<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Auth\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Redirects already signed-in users away from guest-only pages.
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->check()) {
            return (new ResponseFactory())
                ->createResponse(302)
                ->withHeader('Location', '/dashboard');
        }

        return $handler->handle($request);
    }
}
