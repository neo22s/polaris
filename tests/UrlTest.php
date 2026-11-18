<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function testAcceptsLocalPaths(): void
    {
        $this->assertSame('/dashboard', Url::safePath('/dashboard'));
        $this->assertSame('/admin/polars/12', Url::safePath('/admin/polars/12'));
        $this->assertSame('/account/delete/abc123', Url::safePath('/account/delete/abc123'));
        $this->assertSame('/search?q=ana', Url::safePath('/search?q=ana'));
    }

    public function testRejectsExternalOrMalformedPaths(): void
    {
        $this->assertNull(Url::safePath(null));
        $this->assertNull(Url::safePath(''));
        $this->assertNull(Url::safePath('dashboard'));
        $this->assertNull(Url::safePath('//evil.example'));
        $this->assertNull(Url::safePath('https://evil.example/path'));
        $this->assertNull(Url::safePath('/\\evil.example'));
        $this->assertNull(Url::safePath("/dashboard\r\nLocation: https://evil.example"));
        $this->assertNull(Url::safePath('javascript:alert(1)'));
    }
}
