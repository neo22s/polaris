<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Handle;
use PHPUnit\Framework\TestCase;

final class HandleTest extends TestCase
{
    public function testSlugsADisplayName(): void
    {
        $this->assertSame('aleix-gonzalez-morera', Handle::slug('Aleix González Morera'));
        $this->assertSame('jane-doe', Handle::slug('Jane  Doe'));
        $this->assertSame('maria-1', Handle::slug('María_1'));
    }

    public function testStripsLeadingAndTrailingSeparators(): void
    {
        $this->assertSame('ana', Handle::slug('  Ana!  '));
        $this->assertSame('ana', Handle::slug('---Ana---'));
    }

    public function testRespectsTheMaximumLength(): void
    {
        $slug = Handle::slug('Aleix Gonzalez Morera Fernandez De La Casa');

        $this->assertLessThanOrEqual(Handle::MAX_LENGTH, mb_strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', $slug);
    }

    public function testNamesTooShortToBeAHandleReturnEmpty(): void
    {
        $this->assertSame('', Handle::slug('Al'));
        $this->assertSame('', Handle::slug('!!!'));
        $this->assertSame('', Handle::slug(''));
    }

    public function testAlwaysProducesAValidHandle(): void
    {
        // The expected value is spelled out, so a slug() that returned the
        // wrong thing (or nothing) cannot pass.
        $expected = [
            'Ana' => 'ana',
            'Ana María' => 'ana-maria',
            '  x  ' => '',
            'ÁÉÍÓÚ' => 'aeiou',
            'Naïve Café' => 'naive-cafe',
            'a' => '',
            'zzz' => 'zzz',
        ];

        foreach ($expected as $name => $slug) {
            $this->assertSame($slug, Handle::slug($name), "slug('{$name}')");
        }
    }

    public function testASlugThatIsTooShortIsNotAHandle(): void
    {
        // A name that cannot make a usable handle has no slug at all.
        $this->assertSame('', Handle::slug('a'));
        $this->assertNull(Handle::unique('a', static fn (string $c): bool => false));
    }

    public function testUniqueKeepsAFreeSlug(): void
    {
        $this->assertSame('ana', Handle::unique('Ana', static fn (string $c): bool => false));
    }

    public function testUniqueAddsASuffixWhenTaken(): void
    {
        $taken = ['ana', 'ana-2', 'ana-3'];

        $this->assertSame('ana-4', Handle::unique('Ana', static fn (string $c): bool => in_array($c, $taken, true)));
    }

    public function testUniqueKeepsTheSuffixInsideTheLimit(): void
    {
        $name = 'Nombre Muy Largo Que Ocupa Todo El Ancho Permitido';

        $slug = Handle::unique($name, static fn (string $c): bool => $c === Handle::slug($name));

        $this->assertLessThanOrEqual(Handle::MAX_LENGTH, mb_strlen($slug));
        $this->assertMatchesRegularExpression('/-[0-9]+$/', $slug);
    }

    public function testUniqueGivesUpWhenEverySuffixIsTaken(): void
    {
        $this->assertNull(Handle::unique('Ana', static fn (string $c): bool => true));
    }
}
