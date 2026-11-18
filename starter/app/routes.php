<?php

declare(strict_types=1);

/*
 * Routes.
 *
 * Grouped by access level so the middleware is obvious at a glance:
 *
 *   public   anyone
 *   guest    only when signed out (login)
 *   auth     signed-in users
 *   admin    admins only
 *
 * The catch-all `/{page}` for CMS pages goes last, so it never shadows a named
 * route.
 */

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\SetupController;
use Polaris\Api\ApiKeyMiddleware;
use Polaris\Api\RestRouter;
use Polaris\Middleware\AdminMiddleware;
use Polaris\Middleware\AuthMiddleware;
use Polaris\Middleware\GuestMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    // --- Public ---
    $app->get('/', HomeController::class . ':index')->setName('home');
    // Language-prefixed home: /es, /fr. The kernel switches the locale from the
    // first path segment; `/en` redirects to `/` so each language has one URL.
    $app->get('/{locale:[a-z]{2}}', HomeController::class . ':localized')
        ->setName('home.localized');

    // --- First run ---
    // Disappears (404) as soon as one account exists, so it cannot create a
    // second admin or be used to probe a running site.
    $app->get('/setup', SetupController::class . ':form');
    $app->post('/setup', SetupController::class . ':create');

    // --- Authentication ---
    $app->get('/login', AuthController::class . ':form')
        ->add(GuestMiddleware::class);
    $app->post('/login', AuthController::class . ':submit')
        ->add(GuestMiddleware::class);
    // The magic-link callback itself must stay reachable while signed out.
    $app->get('/auth/{token:[a-f0-9]{64}}', AuthController::class . ':consume');
    $app->post('/logout', AuthController::class . ':logout')
        ->add(AuthMiddleware::class);

    // --- Signed-in ---
    $app->get('/dashboard', HomeController::class . ':dashboard')
        ->add(AuthMiddleware::class);

    // --- Admin ---
    $app->get('/admin', AdminController::class . ':index')
        ->add(AdminMiddleware::class);

    // Scaffolded admin screens go here. `php bin/make.php crud` prints the exact
    // block to paste, so this file stays readable.

    // --- API ---
    // Every operation declared in app/Api is mounted here as REST. The same
    // definitions are the MCP tools, so nothing is written twice.
    RestRouter::mount($app, $container->get(Polaris\Api\Registry::class), '/api/v1', [
        ApiKeyMiddleware::class,
    ]);

    $app->get('/api/v1/openapi.json', Polaris\Api\DocsController::class . ':openApi');
    $app->get('/api/v1/docs', Polaris\Api\DocsController::class . ':html');

    $app->post('/api/mcp', Polaris\Api\McpController::class . ':handle')
        ->add('polaris.mcp.key');
};
