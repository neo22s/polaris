<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Polaris\Support\Url;

/**
 * One example test, so `composer check` has something to run on a fresh site.
 *
 * Delete it when the site has real tests; keep the shape.
 */
final class UrlTest extends TestCase
{
    public function testARelativePathIsAccepted(): void
    {
        $this->assertSame('/dashboard', Url::safePath('/dashboard'));
    }

    public function testAnAbsoluteUrlIsRefused(): void
    {
        // Open redirects are the reason this helper exists.
        $this->assertNull(Url::safePath('https://evil.example/phish'));
        $this->assertNull(Url::safePath('//evil.example'));
        $this->assertNull(Url::safePath("javascript:alert(1)"));
    }
}
