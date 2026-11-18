<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Auth\AuthService;
use Polaris\Support\Flash;
use Polaris\Support\Session;
use Polaris\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Requires a signed-in user; redirects guests to the login page.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->auth->check()) {
            // Remember where they wanted to go so we can return after login.
            Session::set('intended_url', $request->getUri()->getPath());
            (new Flash())->info(Translator::t('flash.login_required'));

            return (new ResponseFactory())
                ->createResponse(302)
                ->withHeader('Location', '/login');
        }

        return $handler->handle($request);
    }
}
