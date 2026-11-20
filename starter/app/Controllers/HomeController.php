<?php

declare(strict_types=1);

namespace App\Controllers;

use Polaris\Support\Translator;
use Polaris\View\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The page every new site starts with.
 *
 * A public home and a dashboard for the signed-in account. Replace them with
 * the real thing: nothing here is load-bearing.
 */
final class HomeController
{
    public function __construct(private View $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->renderHome($response);
    }

    private function renderHome(Response $response): Response
    {
        return $this->view->render($response, 'home.twig', [
            'page_title' => Translator::t('home.title'),
        ]);
    }

    /**
     * The same home page under a language prefix: `/es`, `/fr`.
     *
     * The kernel already switches the translator when the first path segment is
     * a supported locale, so this only has to render. `/en` redirects to `/`
     * to keep one canonical URL per language.
     */
    /** @param array{locale:string} $args */
    public function localized(Request $request, Response $response, array $args): Response
    {
        $locale = strtolower((string) ($args['locale'] ?? ''));

        if (!Translator::isSupported($locale)) {
            throw new \Slim\Exception\HttpNotFoundException($request);
        }

        if ($locale === Translator::DEFAULT) {
            return $response->withHeader('Location', '/')->withStatus(301);
        }

        return $this->renderHome($response);
    }

    public function dashboard(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'dashboard.twig', [
            'page_title' => Translator::t('dashboard.title'),
        ]);
    }
}
