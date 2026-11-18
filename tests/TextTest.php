<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testAnEmptyValueBecomesNull(): void
    {
        $this->assertNull(Text::nullable(null));
        $this->assertNull(Text::nullable(''));
        $this->assertNull(Text::nullable('   '));
    }

    public function testAValueIsTrimmed(): void
    {
        $this->assertSame('Madrid', Text::nullable('  Madrid  '));
    }

    public function testAZeroIsKept(): void
    {
        $this->assertSame('0', Text::nullable('0'));
    }
}
