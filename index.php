<?php

declare(strict_types=1);

/*
 * Front controller for a Polaris site.
 *
 * The project root is the web root, so .htaccess rewrites every request that is
 * not a real file here. This file wires three things: the autoloader, the kernel
 * boot, and the application's own definitions and routes.
 */

ini_set('log_errors', 'On');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = __DIR__;

// Guard against a deploy without dependencies: vendor is uploaded by hand on
// shared hosting, so it can genuinely be missing.
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    echo 'Dependencies are not installed. Upload the vendor directory or run "composer install".';
    exit;
}

require $root . '/vendor/autoload.php';

$definitions = is_file($root . '/config/definitions.php')
    ? (require $root . '/config/definitions.php')
    : [];

$routes = is_file($root . '/app/routes.php')
    ? (require $root . '/app/routes.php')
    : null;

$decorateTwig = is_file($root . '/config/twig.php')
    ? (require $root . '/config/twig.php')
    : null;

$app = Polaris\Http\Kernel::boot($root, $definitions, $routes, $decorateTwig);

$app->run();
