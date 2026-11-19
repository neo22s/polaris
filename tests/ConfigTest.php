<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Support\Config;

/**
 * The merge semantics are a public contract: helpers without a container read
 * the result through Config::get(), so a misunderstood `null` would silently
 * re-enable something a site had switched off.
 */
final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testLeafOverridesAreDeepMerged(): void
    {
        $config = Config::merge(
            ['settings' => ['mail' => ['from' => 'kernel@example.com', 'transport' => 'sendmail']]],
            ['settings' => ['mail' => ['from' => 'site@example.com']]],
        );

        $this->assertSame(['from' => 'site@example.com', 'transport' => 'sendmail'], $config['settings']['mail']);
        $this->assertSame('site@example.com', Config::get('mail.from'));
    }

    public function testExplicitNullRemovesAKey(): void
    {
        $config = Config::merge(['settings' => ['mail' => ['from' => 'a', 'bcc' => 'b']]], [
            'settings' => ['mail' => ['bcc' => null]],
        ]);

        $this->assertSame(['from' => 'a'], $config['settings']['mail']);
    }

    public function testListsAreReplacedRatherThanMerged(): void
    {
        $config = Config::merge(['settings' => ['locales' => ['en', 'es']]], [
            'settings' => ['locales' => ['fr']],
        ]);

        $this->assertSame(['fr'], $config['settings']['locales']);
    }

    public function testMissingKeysFallBackAndStateCanBeReset(): void
    {
        Config::merge(['settings' => ['debug' => false]], []);

        $this->assertFalse(Config::get('debug', true));
        $this->assertSame('fallback', Config::get('missing', 'fallback'));

        Config::reset();

        $this->assertSame([], Config::all());
    }
}
