<?php

declare(strict_types=1);

namespace Polaris\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The database the integration tests use, or a fast, honest skip.
 *
 * Two traps this avoids:
 *
 * - **A hanging suite.** `PDO::ATTR_TIMEOUT` does not cover the connect phase,
 *   and an unreachable host (a typo, a CI service that never started) blocks on
 *   the OS TCP timeout — two minutes of a green-looking job. A one-second
 *   `fsockopen` probe fails immediately instead.
 * - **A skipped test that looks like a pass.** With
 *   `POLARIS_REQUIRE_INTEGRATION=1` a missing database fails the run; CI and the
 *   deploy gate set it.
 */
final class Integration
{
    /** Seconds to wait for the TCP connection before declaring the database absent. */
    private const PROBE_TIMEOUT = 1;

    /**
     * A PDO connection to the test database, or the test stops.
     */
    public static function pdo(TestCase $test): PDO
    {
        $host = (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);

        // Probe first: a closed port fails now, not after the OS timeout.
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, self::PROBE_TIMEOUT);

        if ($socket === false) {
            self::stop($test, sprintf(
                'Cannot reach MySQL at %s:%d (%s)',
                $host,
                $port,
                $errorMessage !== '' ? $errorMessage : 'connection refused',
            ));
        }

        fclose($socket);

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d', $host, $port),
                (string) (getenv('TEST_DB_USERNAME') ?: 'root'),
                (string) (getenv('TEST_DB_PASSWORD') ?: ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ],
            );
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::stop($test, 'Cannot connect to MySQL: ' . $e->getMessage());
        }

        return $pdo;
    }

    /**
     * The configured test database name.
     */
    public static function databaseName(): string
    {
        return (string) (getenv('TEST_DB_DATABASE') ?: 'polaris_test');
    }

    /** Stop the test: fail in CI, skip locally. */
    private static function stop(TestCase $test, string $reason): never
    {
        if (getenv('POLARIS_REQUIRE_INTEGRATION') === '1') {
            $test->fail('Integration tests are required (POLARIS_REQUIRE_INTEGRATION=1): ' . $reason);
        }

        $test->markTestSkipped($reason);
    }

    /** A database name that cannot collide with a real one. */
    public static function temporaryDatabaseName(): string
    {
        return 'polaris_test_' . bin2hex(random_bytes(4));
    }
}
