<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * The page of a paginated list.
 *
 * Holds the arithmetic that every paginated view needs, so the page number
 * is clamped to the available pages in one place instead of in every
 * controller.
 */
final class Paginator
{
    private int $page;

    private int $pages;

    public function __construct(
        private int $total,
        private int $perPage = 20,
        int $page = 1,
    ) {
        $this->perPage = max(1, $perPage);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = min(max(1, $page), $this->pages);
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * How many rows to skip to reach the current page.
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
