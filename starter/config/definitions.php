<?php

declare(strict_types=1);

/*
 * Container definitions for this site.
 *
 * Autowiring covers most classes. A definition is only needed when a
 * constructor takes a scalar, an array, or an interface the kernel does not
 * bind on its own.
 *
 * These are the two bindings every site needs:
 *
 * - UserStore       where accounts live (the kernel's users table by default)
 * - ProfileProvisioner  extra rows a new account needs (none here)
 */

use Polaris\Api\ApiKeyMiddleware;
use Polaris\Api\DocsController;
use Polaris\Api\McpController;
use Polaris\Api\Registry;
use Polaris\Mail\Mailer;
use Polaris\Mail\MailerInterface;
use Polaris\Repositories\UserRepository;
use Polaris\Services\NewsletterSyncService;
use Polaris\Repositories\RateLimitRepository;
use Polaris\Http\Kernel;
use Polaris\View\View;
use Psr\Container\ContainerInterface;
use Twig\Environment;

return [
    UserRepository::class => static fn (ContainerInterface $c): UserRepository => new UserRepository(
        $c->get(Polaris\Support\Database::class),
    ),

    // The kernel's auth interfaces, resolved through this site's repository.
    Polaris\Auth\UserStore::class => static fn (ContainerInterface $c): Polaris\Auth\UserStore => $c->get(UserRepository::class),
    Polaris\Auth\HandleStore::class => static fn (ContainerInterface $c): Polaris\Auth\HandleStore => $c->get(UserRepository::class),
    Polaris\Auth\NewsletterAudience::class => static fn (ContainerInterface $c): Polaris\Auth\NewsletterAudience => $c->get(UserRepository::class),
    Polaris\Auth\SubscriberSync::class => static fn (ContainerInterface $c): Polaris\Auth\SubscriberSync => $c->get(NewsletterSyncService::class),
    Polaris\Auth\AuthService::class => \DI\autowire()
        ->constructorParameter('settings', \DI\get('settings')),

    // This site sends one kind of email: the login link.
    MailerInterface::class => static fn (ContainerInterface $c): MailerInterface => $c->get(Mailer::class),
    Mailer::class => static fn (ContainerInterface $c): Mailer => new Mailer(
        $c->get('settings'),
        $c->get(Polaris\Support\Translator::class),
    ),

    // API surfaces. Two keys: a REST key must not reach the tool surface.
    ApiKeyMiddleware::class => static fn (ContainerInterface $c): ApiKeyMiddleware => new ApiKeyMiddleware(
        $c->get('settings'),
        $c->get(RateLimitRepository::class),
        'rest',
    ),
    'polaris.mcp.key' => static fn (ContainerInterface $c): ApiKeyMiddleware => new ApiKeyMiddleware(
        $c->get('settings'),
        $c->get(RateLimitRepository::class),
        'mcp',
    ),

    McpController::class => static fn (ContainerInterface $c): McpController => new McpController(
        $c->get(Registry::class),
        [
            'name' => (string) $c->get('settings')['app_name'] . ' MCP',
            'version' => Kernel::VERSION,
        ],
        (int) ($c->get('settings')['mcp']['max_bytes'] ?? 4 * 1024 * 1024),
    ),

    DocsController::class => static fn (ContainerInterface $c): DocsController => new DocsController(
        $c->get(Registry::class),
        (string) $c->get('settings')['app_name'],
        (string) $c->get('settings')['url'],
        Kernel::VERSION,
        (bool) ($c->get('settings')['api']['docs'] ?? false),
    ),

    Registry::class => static fn (ContainerInterface $c): Registry => App\Api\Groups::registry($c),
];
