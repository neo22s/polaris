<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Support\Translator;
use Polaris\View\TemplateDataMiddleware;
use Polaris\View\ViewGlobalsProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The locale rules of a request.
 *
 * These catch the classic multilingual bug: a page whose body is translated but
 * whose layout, navigation and `<html lang>` still claim the previous language.
 *
 * AuthService is a concrete class with session state, so the middleware is
 * built with its real dependencies but no signed-in user: every test here is
 * about an anonymous visitor.
 */
final class LocaleMiddlewareTest extends TestCase
{
    /** @var array<int,string> */
    private array $langDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->langDirs as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }

        $this->langDirs = [];

        parent::tearDown();
    }

    public function testTheUrlPrefixWinsOverTheBrowser(): void
    {
        $this->assertLocaleForPath('/es/faq', 'en-US,en', 'es');
    }

    public function testTheBrowserDecidesWhenThereIsNoPrefix(): void
    {
        $this->assertLocaleForPath('/', 'es-ES,es', 'es');
    }

    public function testAnUnsupportedPrefixIsNotALocale(): void
    {
        $this->assertLocaleForPath('/especial', 'en-US,en', 'en');
    }

    public function testTheSwitcherListsOnlyTheShippedLanguages(): void
    {
        [$middleware, $twig] = $this->middleware(['en', 'es']);

        $this->render($middleware, '/', 'en-US,en');

        $this->assertSame(['en', 'es'], $twig->getGlobals()['supported_locales']);
    }

    public function testASingleLanguageSiteDoesNotOfferASecondOne(): void
    {
        [$middleware, $twig] = $this->middleware(['en']);

        $this->render($middleware, '/es/faq', 'es-ES,es');

        $this->assertSame(['en'], $twig->getGlobals()['supported_locales']);
        $this->assertSame('en', $twig->getGlobals()['locale']);
    }

    public function testApplicationGlobalsAreExposed(): void
    {
        [$middleware, $twig] = $this->middleware(['en', 'es']);

        $this->render($middleware, '/', 'en-US,en');

        $this->assertArrayHasKey('brand', $twig->getGlobals());
    }

    public function testBothTheLayoutAndTheStringsUseTheUrlLocale(): void
    {
        [$middleware, $twig, $translator] = $this->middleware(['en', 'es']);

        $this->render($middleware, '/es/faq', 'en-US,en');

        // The global the layout prints and the translation the body uses must
        // agree: this is the bug the middleware exists to prevent.
        $this->assertSame('es', $twig->getGlobals()['locale']);
        $this->assertSame('es', $translator->getLocale());
        $this->assertSame('Guardar', $translator->trans('common.save'));
    }

    /**
     * @param array<int,string> $locales
     * @return array{0:TemplateDataMiddleware, 1:Environment, 2:Translator}
     */
    private function middleware(array $locales): array
    {
        $directory = sys_get_temp_dir() . '/polaris_locale_' . bin2hex(random_bytes(4));
        mkdir($directory, 0775, true);
        $this->langDirs[] = $directory;

        file_put_contents(
            $directory . '/en.php',
            "<?php\nreturn ['common.save' => 'Save'];\n",
        );
        file_put_contents(
            $directory . '/es.php',
            "<?php\nreturn ['common.save' => 'Guardar'];\n",
        );

        $translator = new Translator(
            [dirname(__DIR__) . '/resources/lang', $directory],
            'en',
            null,
            $locales,
        );

        $twig = new Environment(
            new ChainLoader([
                new FilesystemLoader(dirname(__DIR__) . '/resources/views'),
                new ArrayLoader(['test' => '<p>{{ locale }}</p>']),
            ]),
            ['cache' => false, 'autoescape' => 'html'],
        );
        $twig->addFunction(new TwigFunction(
            't',
            static fn (string $key, array $params = []): string => $translator->trans($key, $params),
        ));

        $middleware = new TemplateDataMiddleware(
            $twig,
            // No user in this request: the middleware only asks for the
            // session state, and a fresh process has none.
            $this->noUser(),
            new \Polaris\Support\Flash(),
            $translator,
            [new class implements ViewGlobalsProvider {
                public function globals(): array
                {
                    return ['brand' => 'Test'];
                }
            }],
        );

        return [$middleware, $twig, $translator];
    }

    private function noUser(): \Polaris\Auth\AuthService
    {
        $db = new \Polaris\Support\Database([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'polaris_locale_test',
            'username' => 'root',
            'password' => '',
        ]);

        // A stub repository would need the whole kernel; instead the middleware
        // is exercised with a guest by clearing the session key it reads.
        \Polaris\Support\Session::remove(\Polaris\Auth\AuthService::SESSION_KEY);

        return new \Polaris\Auth\AuthService(
            new class ($db) implements \Polaris\Auth\UserStore {
                public function __construct(private \Polaris\Support\Database $db)
                {
                }

                public function findById(int $id): ?array
                {
                    return null;
                }

                public function findByEmail(string $email): ?array
                {
                    return null;
                }

                public function create(array $data): int
                {
                    return 0;
                }

                public function update(int $id, array $data): void
                {
                }

                public function markEmailVerified(int $id): void
                {
                }

                public function promote(int $id): void
                {
                }

                public function setStatus(int $id, string $status): void
                {
                }
            },
            new \Polaris\Repositories\MagicLinkRepository($db),
            new \Polaris\Services\MagicLinkService(
                new \Polaris\Repositories\MagicLinkRepository($db),
                ['url' => 'http://localhost'],
            ),
            new class implements \Polaris\Mail\MailerInterface {
                public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool
                {
                    return true;
                }

                public function sendMagicLink(string $to, string $link): bool
                {
                    return true;
                }
            },
            new \Polaris\Auth\NullSubscriberSync(),
            new \Polaris\Auth\NullProfileProvisioner(),
            $db,
            new \Polaris\Support\RateLimiter($db),
            [],
        );
    }

    private function assertLocaleForPath(string $path, string $acceptLanguage, string $expected): void
    {
        [$middleware, $twig] = $this->middleware(['en', 'es']);

        $this->render($middleware, $path, $acceptLanguage);

        $this->assertSame($expected, $twig->getGlobals()['locale']);
    }

    private function render(TemplateDataMiddleware $middleware, string $path, string $acceptLanguage): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withHeader('Accept-Language', $acceptLanguage);

        return $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        });
    }
}
