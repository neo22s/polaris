<?php

declare(strict_types=1);

namespace Polaris\Repositories;

use Polaris\Support\Clock;
use Polaris\Support\Database;

/**
 * Rate-limit counters, one row per bucket.
 *
 * The policy (how many attempts over what window) belongs to the callers; this
 * class only owns the SQL that counts them.
 */
final class RateLimitRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Register a hit for the given bucket and return true when still within the limit.
     *
     * The counter is raised and read back in one statement, so two requests
     * arriving at the same time cannot both see a count that is still below
     * the limit and then both count themselves in. The read is deliberately
     * separate: it can only ever report a higher count, never a lower one, so
     * a race between the two statements fails closed.
     */
    public function attempt(string $bucket, int $maxAttempts, int $decaySeconds): bool
    {
        $key = hash('sha256', $bucket);
        $now = time();

        // One row per bucket. An expired window starts over; the counter is
        // capped so a flood of requests cannot overflow the column.
        // Every placeholder is used once: native prepares reject a repeated
        // named parameter, so the current time is bound twice.
        $expiresAt = Clock::at($now + $decaySeconds);

        $this->db->execute(
            'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (:bucket, 1, :new_expires)
             ON DUPLICATE KEY UPDATE
                hits = LEAST(IF(expires_at < :now, 1, hits + 1), :cap),
                expires_at = IF(expires_at < :now_again, :reset_expires, expires_at)',
            [
                'bucket' => $key,
                'new_expires' => $expiresAt,
                'now' => Clock::at($now),
                'now_again' => Clock::at($now),
                'cap' => $maxAttempts + 1,
                'reset_expires' => $expiresAt,
            ],
        );

        $row = $this->db->selectOne(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket',
            ['bucket' => $key],
        );

        return (int) ($row['hits'] ?? 0) <= $maxAttempts;
    }

    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM rate_limits WHERE expires_at < :now',
            ['now' => Clock::now()],
        );
    }
}
