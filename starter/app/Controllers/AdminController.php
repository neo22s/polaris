<?php

declare(strict_types=1);

namespace App\Controllers;

use Polaris\Auth\AuthService;
use Polaris\Http\Kernel;
use Polaris\Repositories\MagicLinkRepository;
use Polaris\Repositories\TranslationRepository;
use Polaris\Support\Cache;
use Polaris\Support\Config;
use Polaris\Support\SystemStatus;
use Polaris\Support\Translator;
use Polaris\View\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The admin home.
 *
 * A dashboard, not a CRUD: it answers "is this site healthy?" in one screen,
 * which is the question an admin actually opens it for. Every value here is
 * something an install can go wrong on — a stale cron, a cache that cannot
 * write, a debug flag left on in production.
 */
final class AdminController
{
    public function __construct(
        private View $view,
        private AuthService $auth,
        private Cache $cache,
        private SystemStatus $system,
        private MagicLinkRepository $magicLinks,
        private TranslationRepository $translations,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/index.twig', [
            'page_title' => Translator::t('admin.title'),
            'user' => $this->auth->user(),
            'health' => [
                'php' => PHP_VERSION,
                'kernel' => Kernel::VERSION,
                'site' => (string) Config::get('site_version', '0.0.0'),
                'debug' => (bool) Config::get('debug', false),
                'cache' => $this->system->cacheStats(),
                'cron' => $this->system->read(),
                'seconds_since_cron' => $this->system->secondsSinceLastRun(),
                'pending_links' => $this->countPendingLinks(),
                'translation_overrides' => $this->translations->count(),
            ],
        ]);
    }

    /**
     * How many login links are still valid.
     *
     * Counted here instead of adding a method to the repository for one screen:
     * a generic `count()` would invite callers that do not need it, and this is
     * the only consumer.
     */
    private function countPendingLinks(): int
    {
        // The repository has no count; the magic_links table is small (links
        // expire in 15 minutes) so this is a cheap read.
        return count($this->magicLinks->validNow());
    }
}
