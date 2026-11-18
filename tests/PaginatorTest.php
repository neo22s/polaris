<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Paginator;
use Polaris\Support\Text;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    public function testTheFirstPageStartsAtTheBeginning(): void
    {
        $paginator = new Paginator(45, 20, 1);

        $this->assertSame(1, $paginator->page());
        $this->assertSame(3, $paginator->pages());
        $this->assertSame(0, $paginator->offset());
        $this->assertSame(20, $paginator->perPage());
        $this->assertSame(45, $paginator->total());
    }

    public function testTheLastPageStartsAtTheRightOffset(): void
    {
        $paginator = new Paginator(45, 20, 3);

        $this->assertSame(3, $paginator->page());
        $this->assertSame(40, $paginator->offset());
    }

    public function testAPageBeyondTheLastOneIsClamped(): void
    {
        $paginator = new Paginator(45, 20, 99);

        $this->assertSame(3, $paginator->page());
        $this->assertSame(40, $paginator->offset());
    }

    public function testAPageBelowOneIsClamped(): void
    {
        $paginator = new Paginator(45, 20, 0);

        $this->assertSame(1, $paginator->page());
        $this->assertSame(0, $paginator->offset());
    }

    public function testAnEmptyListStillHasOnePage(): void
    {
        $paginator = new Paginator(0, 20, 3);

        $this->assertSame(1, $paginator->pages());
        $this->assertSame(1, $paginator->page());
        $this->assertSame(0, $paginator->offset());
    }

    public function testThePageSizeIsNeverZero(): void
    {
        $paginator = new Paginator(10, 0, 1);

        $this->assertSame(1, $paginator->perPage());
        $this->assertSame(10, $paginator->pages());
    }
}
