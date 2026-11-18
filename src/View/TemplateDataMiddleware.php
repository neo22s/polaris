<?php

declare(strict_types=1);

namespace Polaris\View;

use Polaris\Auth\AuthService;
use Polaris\Support\Csrf;
use Polaris\Support\Flash;
use Polaris\Support\Session;
use Polaris\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;

/**
 * Resolves the request locale and exposes the shared Twig globals.
 *
 * The kernel sets the globals every site needs. The application adds its own
 * through ViewGlobalsProvider entries, so a starter kit does not have to know
 * what "gender", "cart" or "workspace" means.
 *
 * Locale priority, highest first:
 *
 *   1. the URL prefix (`/es/faq`), because it is an explicit per-request choice
 *      and the language switcher links to it;
 *   2. the account preference;
 *   3. the session choice;
 *   4. the Accept-Language header;
 *   5. the default language.
 *
 * The URL wins on purpose: a Spanish link must render in Spanish even for a
 * visitor whose account is set to English. Without this, a language-prefixed
 * route would show the previous language's navigation while the page body was
 * translated, which is exactly the bug a multilingual site ships with.
 */
final class TemplateDataMiddleware implements MiddlewareInterface
{
    /** @param array<int,ViewGlobalsProvider> $providers */
    public function __construct(
        private Environment $twig,
        private AuthService $auth,
        private Flash $flash,
        private Translator $translator,
        private array $providers = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->user();
        $impersonator = $this->auth->impersonator();

        $sessionLocale = Session::get('locale');
        $locale = $this->localeFromPath($request->getUri()->getPath())
            ?? Translator::detect(
                $user['locale'] ?? null,
                is_string($sessionLocale) ? $sessionLocale : null,
                $request->getHeaderLine('Accept-Language'),
            );

        $this->translator->setLocale($locale);

        $this->twig->addGlobal('current_user', $user);
        $this->twig->addGlobal('impersonator', $impersonator);
        $this->twig->addGlobal('flash_messages', $this->flash->pull());
        $this->twig->addGlobal('csrf_token', Csrf::token());
        $this->twig->addGlobal('current_path', $request->getUri()->getPath());
        $this->twig->addGlobal('locale', $locale);
        $this->twig->addGlobal('supported_locales', $this->translator->supportedLocales());

        foreach ($this->providers as $provider) {
            foreach ($provider->globals() as $name => $value) {
                $this->twig->addGlobal($name, $value);
            }
        }

        return $handler->handle($request);
    }

    /**
     * The locale declared by the first path segment, when it is a supported one.
     *
     * Only an exact match counts: `/es` and `/es/faq` set Spanish, while
     * `/especial` is a page, not a language.
     */
    private function localeFromPath(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));

        if ($segments === [] || $segments[0] === '') {
            return null;
        }

        $candidate = strtolower($segments[0]);

        return $this->translator->supports($candidate) ? $candidate : null;
    }
}
