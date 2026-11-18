<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Polaris\Deploy\MigrationRunner;
use Polaris\Support\Database;

/**
 * The migration runner is the one thing that touches a production database on
 * every deploy, so its guarantees are tested directly:
 *
 * - it runs each file once;
 * - a second run is a no-op;
 * - a file that fails leaves no record, so the release can be retried;
 * - it only sees files from the directories it was given.
 *
 * These tests are skipped, with a clear message, when MySQL is unavailable.
 */
final class MigrationRunnerTest extends TestCase
{
    private ?Database $db = null;

    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = 'polaris_migration_test_' . bin2hex(random_bytes(4));

        try {
            $root = new PDO(
                sprintf('mysql:host=%s;port=%d', $this->host(), $this->port()),
                (string) (getenv('TEST_DB_USERNAME') ?: 'root'),
                (string) (getenv('TEST_DB_PASSWORD') ?: ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $root->exec('CREATE DATABASE `' . $this->database . '`');
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL is not available: ' . $e->getMessage());
        }

        $this->db = new Database([
            'host' => $this->host(),
            'port' => $this->port(),
            'database' => $this->database,
            'username' => (string) (getenv('TEST_DB_USERNAME') ?: 'root'),
            'password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->pdo()->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }

        parent::tearDown();
    }

    public function testRunsTheKernelUpgradeOnce(): void
    {
        $this->assertNotNull($this->db);

        $ran = MigrationRunner::run($this->db, [dirname(__DIR__) . '/database/upgrades']);

        $this->assertSame(['00000000000001_core.sql'], $ran);
        $this->assertSame(0, count(MigrationRunner::run($this->db, [dirname(__DIR__) . '/database/upgrades'])));
        $this->assertSame([], MigrationRunner::pendingFiles($this->db, [dirname(__DIR__) . '/database/upgrades']));
    }

    public function testCreatesTheCoreTables(): void
    {
        $this->assertNotNull($this->db);

        MigrationRunner::run($this->db, [dirname(__DIR__) . '/database/upgrades']);

        $tables = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $this->db->select('SHOW TABLES'),
        );

        foreach (['users', 'magic_links', 'settings', 'translation_overrides', 'rate_limits'] as $table) {
            $this->assertContains($table, $tables, 'The core schema must create ' . $table . '.');
        }
    }

    public function testAppliesKernelUpgradesBeforeApplicationOnes(): void
    {
        $this->assertNotNull($this->db);

        $directory = sys_get_temp_dir() . '/polaris_migrations_' . bin2hex(random_bytes(4));
        mkdir($directory);

        file_put_contents(
            $directory . '/00000000000002_second.sql',
            'CREATE TABLE IF NOT EXISTS `second_table` (`id` int unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));',
        );

        try {
            $ran = MigrationRunner::run($this->db, [
                dirname(__DIR__) . '/database/upgrades',
                $directory,
            ]);

            // The kernel file sorts first and is applied first.
            $this->assertSame(['00000000000001_core.sql', '00000000000002_second.sql'], $ran);
        } finally {
            @unlink($directory . '/00000000000002_second.sql');
            @rmdir($directory);
        }
    }

    public function testAFailingFileLeavesNoRecordSoItCanBeRetried(): void
    {
        $this->assertNotNull($this->db);

        $directory = sys_get_temp_dir() . '/polaris_migrations_' . bin2hex(random_bytes(4));
        mkdir($directory);

        $broken = $directory . '/00000000000003_broken.sql';
        file_put_contents($broken, 'THIS IS NOT SQL;');

        try {
            try {
                MigrationRunner::run($this->db, [$directory]);
                $this->fail('A broken migration must throw.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('00000000000003_broken.sql', $e->getMessage());
            }

            $this->assertSame(
                ['00000000000003_broken.sql'],
                MigrationRunner::pendingFiles($this->db, [$directory]),
                'A failed migration must stay pending.',
            );
        } finally {
            @unlink($broken);
            @rmdir($directory);
        }
    }

    private function host(): string
    {
        return (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1');
    }

    private function port(): int
    {
        return (int) (getenv('TEST_DB_PORT') ?: 3306);
    }
}
