<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
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

    public function testTokenIsStableWithinSession(): void
    {
        $first = Csrf::token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame($first, Csrf::token());
    }

    public function testValidateAcceptsTheCurrentToken(): void
    {
        $this->assertTrue(Csrf::validate(Csrf::token()));
    }

    public function testValidateRejectsMissingOrWrongToken(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
        $this->assertFalse(Csrf::validate('not-the-token'));
    }

    public function testValidateRejectsWhenNoTokenWasIssued(): void
    {
        $this->assertFalse(Csrf::validate('anything'));
    }

    public function testFieldRendersAHiddenInput(): void
    {
        $field = Csrf::field();

        $this->assertStringContainsString('name="_token"', $field);
        $this->assertStringContainsString(Csrf::token(), $field);
    }
}
