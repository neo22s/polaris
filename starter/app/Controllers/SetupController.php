<?php

declare(strict_types=1);

namespace App\Controllers;

use Polaris\Auth\AuthService;
use Polaris\Repositories\UserRepository;
use Polaris\Services\MagicLinkService;
use Polaris\Support\Translator;
use Polaris\Support\Validator;
use Polaris\View\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * First-run account creation.
 *
 * A site deployed as a zip has no installer, so somebody has to create the first
 * admin. This route does exactly that, and only that: while an account exists it
 * returns 404, so it cannot be used to create a second admin or to probe a
 * running site.
 *
 * The link is printed instead of emailed: at this point SMTP is usually not
 * configured yet, and an admin who cannot get in cannot configure it.
 */
final class SetupController
{
    public function __construct(
        private View $view,
        private UserRepository $users,
        private AuthService $auth,
        private MagicLinkService $linkService,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        if ($this->alreadySetUp()) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'setup.twig', [
            'page_title' => Translator::t('setup.title'),
            'error' => null,
            'link' => null,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if ($this->alreadySetUp()) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        $validator = new Validator(['email' => $email], ['email' => 'required|email']);

        if ($validator->fails()) {
            return $this->view->render($response, 'setup.twig', [
                'page_title' => Translator::t('setup.title'),
                'error' => (string) $validator->firstError(),
                'link' => null,
            ])->withStatus(422);
        }

        // The account is created and treated as verified: whoever reaches a site
        // with no accounts is installing it, and an unverified first admin would
        // lock itself out until SMTP exists.
        $user = $this->auth->createAccount($email, 'admin', ['locale' => \Polaris\Support\Translator::instance()->getLocale()]);

        $this->users->markEmailVerified((int) $user['id']);

        // The link is printed, not emailed: at this point SMTP usually is not
        // configured, and an admin who cannot get in cannot configure it.
        $link = $this->linkService->create($user, null, MagicLinkService::TTL_LOGIN);

        return $this->view->render($response, 'setup.twig', [
            'page_title' => Translator::t('setup.title'),
            'error' => null,
            'link' => $link,
        ]);
    }

    private function alreadySetUp(): bool
    {
        return $this->users->countAll() > 0;
    }
}
