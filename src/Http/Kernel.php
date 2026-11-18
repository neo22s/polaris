<?php

declare(strict_types=1);

namespace Polaris\Http;

use DI\Container;
use DI\ContainerBuilder;
use Polaris\Auth\AuthService;
use Polaris\Auth\NewsletterAudience;
use Polaris\Auth\NullProfileProvisioner;
use Polaris\Auth\NullSubscriberSync;
use Polaris\Auth\ProfileProvisioner;
use Polaris\Auth\SubscriberSync;
use Polaris\Auth\UserStore;
use Polaris\Mail\Mailer;
use Polaris\Mail\MailerInterface;
use Polaris\Middleware\CsrfMiddleware;
use Polaris\Middleware\ErrorPageMiddleware;
use Polaris\Middleware\SecurityHeadersMiddleware;
use Polaris\Middleware\SessionMiddleware;
use Polaris\Repositories\MagicLinkRepository;
use Polaris\Repositories\UserRepository;
use Polaris\Repositories\SettingRepository;
use Polaris\Repositories\TranslationRepository;
use Polaris\Services\AcumbamailService;
use Polaris\Services\MagicLinkService;
use Polaris\Services\NewsletterSyncService;
use Polaris\Services\SettingsService;
use Polaris\Support\Cache;
use Polaris\Support\Config;
use Polaris\Support\Database;
use Polaris\Support\Env;
use Polaris\Support\Flash;
use Polaris\Support\RateLimiter;
use Polaris\Support\SystemStatus;
use Polaris\Support\Translator;
use Polaris\View\TemplateDataMiddleware;
use Polaris\View\TwigFactory;
use Polaris\View\View;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Twig\Environment;

/**
 * Builds the Slim application from configuration.
 *
 * The kernel owns the wiring and the middleware order; an application supplies
 * only its definitions (bindings for the domain interfaces), its routes and its
 * Twig globals. Everything else is a default a site can override, never a thing
 * it has to repeat.
 *
 * Boot order matters and is documented inline: env, config, container, Twig,
 * Slim, middleware.
 */
final class Kernel
{
    public const VERSION = '0.1.0';

    /**
     * @param string $root Site root (the directory holding index.php).
     * @param array<string,mixed> $definitions Extra PHP-DI definitions from the app.
     * @param (\Closure(App, ContainerInterface):void)|null $routes Route registrar.
     * @param (\Closure(Environment, ContainerInterface):void)|null $decorateTwig
     *        Hook for application Twig functions and filters. Runs after the
     *        kernel helpers, so an app can add or replace them.
     * @param string|null $envFile Environment file. Defaults to <root>/.env.
     */
    public static function boot(
        string $root,
        array $definitions = [],
        ?\Closure $routes = null,
        ?\Closure $decorateTwig = null,
        ?string $envFile = null,
    ): App {
        // 1. Environment. A real environment variable wins over the file.
        Env::load($envFile ?? rtrim($root, '/') . '/.env');

        // 2. Configuration: kernel defaults, then the site's overrides.
        $defaults = require dirname(__DIR__, 2) . '/config/defaults.php';
        $overrides = is_file($root . '/config/settings.php')
            ? (require $root . '/config/settings.php')
            : [];

        $config = Config::merge($defaults, $overrides);
        /** @var array<string,mixed> $settings */
        $settings = $config['settings'];

        // 3. Container.
        $container = self::container($root, $settings, $definitions);

        // 4. Twig with the view cascade: theme, app, kernel.
        $theme = (string) ($settings['theme'] ?? 'base');
        $kernelRoot = dirname(__DIR__, 2);
        $twig = TwigFactory::create(
            self::viewPaths($settings, $theme, $kernelRoot),
            $settings,
            (string) $settings['paths']['assets'],
            (string) $settings['paths']['cache'],
            self::themeAssets($settings, $theme),
        );

        $container->set(Environment::class, $twig);
        $container->set(View::class, new View($twig));
        $container->set('view', $container->get(View::class));

        if ($decorateTwig !== null) {
            $decorateTwig($twig, $container);
        }

        // 5. Slim.
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();

        // 6. Middleware. Slim runs the stack last-added-first, so the effective
        //    order is the inverse of the add() calls below:
        //
        //      SecurityHeaders -> ErrorPage -> Session -> TemplateData ->
        //      BodyParsing -> CSRF -> Routing -> controller.
        //
        //    Routing is added first (step 5), which makes it the innermost
        //    layer: CSRF and body parsing have already run when the route is
        //    matched, and the route callable is the last thing to execute.
        $app->add(CsrfMiddleware::class);
        $app->addBodyParsingMiddleware();
        $app->add(TemplateDataMiddleware::class);
        $app->add(SessionMiddleware::class);
        $app->add(ErrorPageMiddleware::class);

        if ($routes !== null) {
            $routes($app, $container);
        }

        // Added last so it wraps every response, errors included.
        $app->add(SecurityHeadersMiddleware::class);

        return $app;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $definitions
     */
    private static function container(string $root, array $settings, array $definitions): Container
    {
        $kernelRoot = dirname(__DIR__, 2);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(array_merge([
            'settings' => $settings,
            'root' => $root,
            // The supported locale list, so a helper without the container can
            // still answer which languages this site ships.
            'locales' => (array) ($settings['locales'] ?? ['en']),

            Database::class => static fn (): Database => new Database($settings['db']),
            Flash::class => static fn (): Flash => new Flash(),
            Cache::class => static fn (): Cache => new Cache(
                (string) $settings['cache']['path'],
                (bool) $settings['cache']['enabled'],
            ),

            // Admin-edited translations sit on top of the language files.
            // Application strings win over kernel strings, so a site can
            // reword a kernel message without touching the kernel file.
            Translator::class => static fn (ContainerInterface $c): Translator => new Translator(
                [
                    (string) $settings['paths']['lang'],
                    $kernelRoot . '/resources/lang',
                ],
                Translator::DEFAULT,
                // A database outage must not take the interface down with it:
                // without overrides the site falls back to the language files.
                static function (string $locale) use ($c): array {
                    try {
                        return $c->get(TranslationRepository::class)->overridesFor($locale);
                    } catch (\Throwable $e) {
                        error_log('[Polaris] translation overrides unavailable: ' . $e->getMessage());

                        return [];
                    }
                },
                (array) ($settings['locales'] ?? ['en']),
            ),

            Mailer::class => static fn (ContainerInterface $c): Mailer => new Mailer(
                $settings,
                $c->get(Translator::class),
            ),
            MailerInterface::class => static fn (ContainerInterface $c): MailerInterface => $c->get(Mailer::class),

            AcumbamailService::class => static fn (): AcumbamailService => new AcumbamailService($settings['acumbamail']),

            // Writable runtime settings belong to the site; the kernel only
            // provides the validated write path.
            SettingsService::class => static fn (ContainerInterface $c): SettingsService => new SettingsService(
                $c->get(SettingRepository::class),
                (array) ($settings['writable_settings'] ?? []),
            ),
            NewsletterSyncService::class => static fn (ContainerInterface $c): NewsletterSyncService => new NewsletterSyncService(
                $c->get(NewsletterAudience::class),
                $c->get(AcumbamailService::class),
            ),

            MagicLinkService::class => static fn (ContainerInterface $c): MagicLinkService => new MagicLinkService(
                $c->get(MagicLinkRepository::class),
                $settings,
            ),

            AuthService::class => static fn (ContainerInterface $c): AuthService => new AuthService(
                $c->get(UserStore::class),
                $c->get(MagicLinkRepository::class),
                $c->get(MagicLinkService::class),
                $c->get(MailerInterface::class),
                $c->get(SubscriberSync::class),
                $c->get(ProfileProvisioner::class),
                $c->get(Database::class),
                $c->get(RateLimiter::class),
                $settings,
            ),

            SessionMiddleware::class => static fn (): SessionMiddleware => new SessionMiddleware(
                (string) $settings['session_name'],
                (int) $settings['session_lifetime'],
                (int) $settings['session_regenerate_minutes'],
                (string) $settings['paths']['sessions'],
                (array) ($settings['trusted_proxies'] ?? []),
            ),

            RateLimiter::class => static fn (ContainerInterface $c): RateLimiter => new RateLimiter(
                $c->get(Database::class),
            ),

            ErrorPageMiddleware::class => static fn (ContainerInterface $c): ErrorPageMiddleware => new ErrorPageMiddleware(
                $c->get(View::class),
                (bool) $settings['debug'],
            ),

            SecurityHeadersMiddleware::class => static fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(
                (array) ($settings['security']['csp'] ?? []),
                (array) ($settings['trusted_proxies'] ?? []),
            ),

            TemplateDataMiddleware::class => static fn (ContainerInterface $c): TemplateDataMiddleware => new TemplateDataMiddleware(
                $c->get(Environment::class),
                $c->get(AuthService::class),
                $c->get(Flash::class),
                $c->get(Translator::class),
            ),

            SystemStatus::class => \DI\autowire()
                ->constructorParameter('statusFile', $root . '/var/logs/cron-status.json')
                ->constructorParameter('cacheDirectory', (string) $settings['cache']['path']),

            // The sources of truth behind the auth interfaces. Override these
            // two bindings to plug the application's own tables in.
            UserStore::class => static fn (ContainerInterface $c): UserStore => $c->get(UserRepository::class),
            NewsletterAudience::class => static fn (ContainerInterface $c): NewsletterAudience => $c->get(UserRepository::class),

            // Optional integrations. A site without a newsletter or a profile
            // table keeps the no-ops; a site with them overrides the binding.
            SubscriberSync::class => static fn (ContainerInterface $c): SubscriberSync => $c->get(NewsletterSyncService::class),
            ProfileProvisioner::class => static fn (): ProfileProvisioner => new NullProfileProvisioner(),
        ], $definitions));

        return $builder->build();
    }

    /**
     * The Twig search order: theme first, then the application, then the kernel.
     *
     * @param array<string,mixed> $settings
     * @return array<int,string>
     */
    private static function viewPaths(array $settings, string $theme, string $kernelRoot): array
    {
        $paths = [];

        if ($theme !== 'base') {
            $themeDirectory = (string) $settings['paths']['themes'] . '/' . $theme . '/views';

            if (is_dir($themeDirectory)) {
                $paths[] = $themeDirectory;
            }
        }

        $paths[] = (string) $settings['paths']['views'];
        $paths[] = $kernelRoot . '/resources/views';

        return $paths;
    }

    /** @param array<string,mixed> $settings */
    private static function themeAssets(array $settings, string $theme): ?string
    {
        if ($theme === 'base') {
            return null;
        }

        $directory = (string) $settings['paths']['themes'] . '/' . $theme . '/assets';

        return is_dir($directory) ? $directory : null;
    }
}
