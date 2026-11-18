<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use Polaris\Support\Translator;

/**
 * Puts the process-wide translator back the way it was.
 *
 * The translator is a singleton: a test that builds one with a temporary
 * language directory would otherwise change the language of every test that
 * runs after it, and the suite would only pass in one order. This trait
 * re-installs the kernel translator before and after each test.
 */
trait RestoresTheTranslator
{
    protected function setUp(): void
    {
        parent::setUp();

        self::restoreKernelTranslator();
    }

    protected function tearDown(): void
    {
        self::restoreKernelTranslator();

        parent::tearDown();
    }

    private static function restoreKernelTranslator(): void
    {
        $translator = new Translator(dirname(__DIR__, 2) . '/resources/lang');
        $translator->setLocale('en');
    }
}
