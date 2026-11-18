<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $previousSession = null;

    protected function setUp(): void
    {
        // The session of the process is put back afterwards: the test must
        // not leave the session of the next one behind.
        $this->previousSession = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->previousSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->previousSession;
        }
    }

    public function testGetSetHasAndRemove(): void
    {
        $this->assertNull(Session::get('missing'));
        $this->assertSame('fallback', Session::get('missing', 'fallback'));

        Session::set('key', 'value');

        $this->assertTrue(Session::has('key'));
        $this->assertSame('value', Session::get('key'));

        Session::remove('key');

        $this->assertFalse(Session::has('key'));
    }

    public function testPullReturnsAndRemovesTheValue(): void
    {
        Session::set('flash', ['ok']);

        $this->assertSame(['ok'], Session::pull('flash'));
        $this->assertFalse(Session::has('flash'));
        $this->assertSame('fallback', Session::pull('flash', 'fallback'));
    }

    public function testKeepOnlyPreservesSelectedKeys(): void
    {
        Session::replace(['locale' => 'es', 'user_id' => 7, 'other' => true]);

        Session::keepOnly(['locale']);

        $this->assertSame(['locale' => 'es'], Session::all());
    }
}
