<?php

declare(strict_types=1);

namespace Polaris\Api;

/**
 * Limit/offset paging that a handler can adopt in one line.
 *
 * Two rules make it safe to expose over an API:
 *
 * - `limit` is clamped. A client asking for 100 000 rows gets the maximum the
 *   site allows, not a timeout and a database incident.
 * - "is there another page" is answered by the data. The query asks for one row
 *   more than the page size; if it comes back, there is more. A `COUNT(*)` on
 *   every list endpoint is the usual alternative and it doubles the cost of
 *   every request for information most clients never use.
 *
 * Offset paging cannot see rows inserted between requests. That is documented
 * rather than hidden: a client that needs a stable walk uses the id it last saw.
 */
final class Pagination
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * @param int $limit Rows to return.
     * @param int $offset Rows to skip.
     * @param bool $hasMore Whether another page exists.
     */
    private function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly bool $hasMore,
    ) {
    }

    /**
     * Read `limit` and `offset` from the arguments a client sent.
     *
     * @param array<string,mixed> $args
     */
    public static function fromArgs(array $args, ?int $max = null): self
    {
        $max = $max === null ? self::MAX_LIMIT : max(1, min($max, self::MAX_LIMIT));

        $limit = (int) ($args['limit'] ?? self::DEFAULT_LIMIT);
        $offset = (int) ($args['offset'] ?? 0);

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        return new self(min($limit, $max), max(0, $offset), false);
    }

    /** The limit to pass to the query: one more row than the page. */
    public function fetchLimit(): int
    {
        return $this->limit + 1;
    }

    /**
     * Trim the extra row and report whether it existed.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{rows:array<int,array<string,mixed>>,pagination:array{limit:int,offset:int,has_more:bool,next_offset:?int}}
     */
    public function apply(array $rows): array
    {
        $hasMore = count($rows) > $this->limit;
        $page = $hasMore ? array_slice($rows, 0, $this->limit) : $rows;

        return [
            'rows' => $page,
            'pagination' => [
                'limit' => $this->limit,
                'offset' => $this->offset,
                'has_more' => $hasMore,
                'next_offset' => $hasMore ? $this->offset + $this->limit : null,
            ],
        ];
    }

    /**
     * The two properties an operation must declare to accept paging.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function schema(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => self::MAX_LIMIT,
                'default' => self::DEFAULT_LIMIT,
                'description' => 'Rows per page. Clamped to ' . self::MAX_LIMIT . '.',
            ],
            'offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'default' => 0,
                'description' => 'Rows to skip.',
            ],
        ];
    }
}
