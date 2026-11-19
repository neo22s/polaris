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
 * Requires an admin user; returns 403 otherwise.
 */
final class AdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $auth,
        private ResponseFactory $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->auth->check()) {
            return $this->responses
                ->createResponse(302)
                ->withHeader('Location', '/login');
        }

        if (!$this->auth->isAdmin()) {
            throw new \Slim\Exception\HttpForbiddenException($request);
        }

        return $handler->handle($request);
    }
}
