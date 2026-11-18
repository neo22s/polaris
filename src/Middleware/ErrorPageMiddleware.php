<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Support\Translator;
use Polaris\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

/**
 * Renders the friendly 404/500 pages (and a debug page in development).
 *
 * This replaces Slim's default error middleware, which was returning an empty
 * body for routing errors. It sits inside SecurityHeadersMiddleware so the
 * error responses still get the security headers.
 */
final class ErrorPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private View $view,
        private bool $debug,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);

            // Safety net: a bare 404 response (empty body) becomes the friendly page.
            if ($response->getStatusCode() === 404 && (string) $response->getBody() === '') {
                return $this->render(404, null);
            }

            return $response;
        } catch (HttpException $exception) {
            $status = $exception->getCode();
            $status = $status >= 400 && $status < 600 ? $status : 500;

            return $this->render($status, $exception);
        } catch (Throwable $exception) {
            return $this->render(500, $exception);
        }
    }

    private function render(int $status, ?Throwable $exception): ResponseInterface
    {
        // Log real server errors; ignore expected 404s.
        if ($exception !== null && $status >= 500) {
            error_log(sprintf('[Polaris] %d %s: %s', $status, $exception::class, $exception->getMessage()));
        }

        $response = (new ResponseFactory())->createResponse($status);

        if ($this->debug && $exception !== null) {
            $response->getBody()->write(
                '<h1>' . $status . '</h1><pre style="white-space:pre-wrap">'
                . htmlspecialchars((string) $exception, ENT_QUOTES) . '</pre>'
            );

            return $response;
        }

        try {
            $template = match ($status) {
                404 => 'errors/404.twig',
                403 => 'errors/403.twig',
                default => 'errors/500.twig',
            };

            return $this->view->render($response, $template, ['status' => $status]);
        } catch (Throwable $renderError) {
            error_log('[Polaris] error page render failed: ' . $renderError->getMessage());

            $response->getBody()->write($this->fallbackMessage($status));

            return $response;
        }
    }

    /**
     * The text of the last resort page.
     *
     * This runs when even the error template could not be rendered, so the
     * translator may be the thing that is broken (a database outage takes the
     * language overrides with it). It is asked for a translated message, but
     * a failure here must not escape: something has to reach the browser.
     */
    private function fallbackMessage(int $status): string
    {
        try {
            return Translator::t(match ($status) {
                404 => 'errors.404_title',
                403 => 'errors.403_title',
                default => 'errors.500_title',
            });
        } catch (Throwable) {
            return match ($status) {
                404 => 'Page not found.',
                403 => 'Forbidden.',
                default => 'Server error.',
            };
        }
    }
}
