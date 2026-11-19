<?php

declare(strict_types=1);

namespace App\Controllers;

use Polaris\Auth\AuthService;
use Polaris\Support\Flash;
use Polaris\Repositories\RateLimitRepository;
use Polaris\Support\Translator;
use Polaris\Support\Url;
use Polaris\Support\Validator;
use Polaris\View\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Passwordless login: ask for a link, then consume it.
 *
 * The answer to "send me a link" is always the same, whether the address exists
 * or not. A different message would turn this form into a way to find out who
 * has an account here.
 */
final class AuthController
{
    public function __construct(
        private View $view,
        private AuthService $auth,
        private Flash $flash,
        private RateLimitRepository $limiter,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'auth/login.twig', [
            'page_title' => Translator::t('auth.login_title'),
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        // Two buckets: one per address so a single victim cannot be spammed,
        // one per IP so a script cannot walk a list of addresses.
        if (!$this->limiter->attempt('login:' . $ip . ':' . $email, 5, 900)
            || !$this->limiter->attempt('login-ip:' . $ip, 20, 900)
        ) {
            return $this->view->render($response, 'auth/login.twig', [
                'page_title' => Translator::t('auth.login_title'),
                'error' => Translator::t('auth.too_many'),
            ])->withStatus(429);
        }

        $validator = new Validator($data, ['email' => 'required|email']);

        if ($validator->fails()) {
            return $this->view->render($response, 'auth/login.twig', [
                'page_title' => Translator::t('auth.login_title'),
                'error' => (string) $validator->firstError(),
            ])->withStatus(422);
        }

        // Null means "no such account" and is deliberately indistinguishable
        // from a successful send.
        $this->auth->requestMagicLink($email, $ip, $request->getHeaderLine('User-Agent'));

        return $this->view->render($response, 'auth/link_sent.twig', [
            'page_title' => Translator::t('auth.link_sent_title'),
            'email' => $email,
        ]);
    }

    public function consume(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->consumeToken((string) $args['token']);

        if ($user === null) {
            $this->flash->error(Translator::t('auth.invalid_link'));

            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->auth->login($user);
        $this->flash->success(Translator::t('auth.welcome', ['name' => (string) ($user['email'] ?? '')]));

        $intended = \Polaris\Support\Session::pull('intended_url');
        $target = Url::safePath($intended) ?? ($user['role'] === 'admin' ? '/admin' : '/dashboard');

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();

        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
