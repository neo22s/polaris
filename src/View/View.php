<?php

declare(strict_types=1);

namespace Polaris\View;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * Thin render helper in place of slim/twig-view.
 *
 * One method and no wrapper types: the Twig environment is created by the
 * factory, configured once, and rendered into an existing PSR-7 response.
 */
final class View
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * Render a template into the response body.
     *
     * @param array<string,mixed> $data
     */
    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, $data));

        return $response;
    }

    public function environment(): Environment
    {
        return $this->twig;
    }

    /**
     * Alias kept for controllers that used slim/twig-view's API, so a site can
     * switch to the kernel without touching every controller.
     */
    public function getEnvironment(): Environment
    {
        return $this->twig;
    }
}
