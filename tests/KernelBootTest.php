<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Auth\AuthService;
use Polaris\Deploy\SiteBuilder;
use Polaris\Http\Kernel;
use Polaris\Middleware\SecurityHeadersMiddleware;
use Polaris\Middleware\SessionMiddleware;
use Polaris\Support\Env;
use Polaris\Support\RateLimiter;
use Slim\App;

/**
 * A boot test catches what unit tests cannot: a container definition that names
 * a class the file never imported, or a service that cannot be constructed from
 * the defaults. Both fail only when the application actually starts.
 */
final class KernelBootTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/polaris_boot_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0775, true);

        file_put_contents(
            $this->root . '/config/settings.php',
            "<?php\nreturn ['settings' => ['env' => 'local']];\n",
        );
    }

    protected function tearDown(): void
    {
        Env::reset();
        SiteBuilder::removeDirectory($this->root);

        parent::tearDown();
    }

    public function testTheApplicationBootsWithTheKernelDefaults(): void
    {
        $app = Kernel::boot($this->root);
        $container = $app->getContainer();

        $this->assertInstanceOf(App::class, $app);

        // The services the request lifecycle depends on, resolved the same way
        // a real request resolves them.
        $this->assertInstanceOf(RateLimiter::class, $container->get(RateLimiter::class));
        $this->assertInstanceOf(AuthService::class, $container->get(AuthService::class));
        $this->assertInstanceOf(
            SecurityHeadersMiddleware::class,
            $container->get(SecurityHeadersMiddleware::class),
        );
        $this->assertInstanceOf(SessionMiddleware::class, $container->get(SessionMiddleware::class));
    }

    public function testTheRoutesHookReceivesTheApplicationAndContainer(): void
    {
        $called = false;

        Kernel::boot($this->root, [], static function ($app, $container) use (&$called): void {
            $called = $app instanceof App && $container !== null;
        });

        $this->assertTrue($called);
    }
}
