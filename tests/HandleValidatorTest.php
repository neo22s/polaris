<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\HandleValidator;
use Polaris\Support\Translator;
use PHPUnit\Framework\TestCase;

final class HandleValidatorTest extends TestCase
{
    /** @param callable(string):bool $isTaken */
    private function check(string $handle, ?callable $isTaken = null): ?string
    {
        return HandleValidator::check($handle, $isTaken ?? static fn (string $candidate): bool => false);
    }

    public function testAcceptsValidHandle(): void
    {
        $this->assertNull($this->check('jane-doe'));
    }

    public function testRejectsReservedHandle(): void
    {
        $this->assertNotNull($this->check('admin'));
        $this->assertNotNull($this->check('dashboard'));
        $this->assertNotNull($this->check('onboarding'));
        $this->assertNotNull($this->check('account'));
    }

    public function testIsReservedIsCaseInsensitive(): void
    {
        $this->assertTrue(HandleValidator::isReserved('Admin'));
        $this->assertFalse(HandleValidator::isReserved('jane-doe'));
    }

    public function testRejectsTakenHandle(): void
    {
        $error = $this->check('jane-doe', static fn (string $candidate): bool => true);

        $this->assertSame(Translator::t('validation.handle_taken'), $error);
    }

    public function testRejectsInvalidFormat(): void
    {
        $this->assertNotNull($this->check('ab'));
        $this->assertNotNull($this->check('jane_doe'));
        $this->assertNotNull($this->check('-jane'));
    }

    public function testRejectsAnEmptyHandle(): void
    {
        $this->assertNotNull($this->check(''));
        $this->assertNotNull($this->check('   '));
    }

    public function testFormatValidatorDirectly(): void
    {
        $this->assertTrue(HandleValidator::isFormatValid('model-2026'));
        $this->assertFalse(HandleValidator::isFormatValid('model_2026'));
    }
}
