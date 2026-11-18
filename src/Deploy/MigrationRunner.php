<?php

declare(strict_types=1);

namespace Polaris\Deploy;

use PDO;
use Polaris\Support\Database;
use RuntimeException;
use Throwable;

/**
 * Applies the SQL upgrade files, once each.
 *
 * Two directories are scanned: the kernel's own upgrades and the application's.
 * The kernel goes first, so a table it adds exists before application SQL that
 * references it runs. Applied filenames are recorded in `_migrations`, so a
 * re-run is a no-op and an interrupted release can be resumed.
 *
 * No transaction wraps a file: MySQL commits DDL implicitly, so a transaction
 * here would be a lie. Instead every file must be idempotent and the runner
 * records only the files that ran to completion. A file that fails halfway
 * leaves nothing recorded, and re-running it is safe.
 */
final class MigrationRunner
{
    /** Name of the MySQL advisory lock that stops two deploys racing. */
    private const LOCK = 'polaris_migrations';

    /**
     * The upgrade directories of a site, kernel first.
     *
     * `database/upgrades` is the convention. `database/sql` is accepted because
     * an application may have started there (a deploy runner that predates the
     * kernel), and renaming the directory of a project mid-life would either
     * break its deploy or apply everything twice.
     *
     * @return array<int,string>
     */
    public static function siteDirectories(string $root): array
    {
        $root = rtrim($root, '/');

        return array_values(array_filter([
            $root . '/kernel/database/upgrades',
            $root . '/database/upgrades',
            $root . '/database/sql',
        ], 'is_dir'));
    }

    /**
     * @param array<int,string> $directories Ordered: kernel, then application.
     * @return array<int,string> The files applied in this run.
     */
    public static function run(Database $db, array $directories): array
    {
        $pdo = $db->pdo();

        self::ensureRegistry($pdo);
        self::lock($pdo);

        $ran = [];

        try {
            $pending = self::pending($directories, self::appliedNames($pdo));

            foreach ($pending as $name => $file) {
                $sql = (string) file_get_contents($file);

                try {
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        sprintf('Migration %s failed: %s', $name, $e->getMessage()),
                        0,
                        $e,
                    );
                }

                $statement = $pdo->prepare(
                    'INSERT INTO _migrations (migration_name, applied_at) VALUES (:name, :at)',
                );
                $statement->execute(['name' => $name, 'at' => date('Y-m-d H:i:s')]);

                $ran[] = $name;
            }
        } finally {
            self::unlock($pdo);
        }

        return $ran;
    }

    /**
     * The files that would run, without running them.
     *
     * @param array<int,string> $directories
     * @return array<int,string>
     */
    public static function pendingFiles(Database $db, array $directories): array
    {
        $pdo = $db->pdo();

        self::ensureRegistry($pdo);

        return array_keys(self::pending($directories, self::appliedNames($pdo)));
    }

    private static function ensureRegistry(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `_migrations` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `migration_name` varchar(191) NOT NULL,
                `applied_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migration_name` (`migration_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function lock(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $statement->execute(['name' => self::LOCK]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the migration lock. Another deploy is running.');
        }
    }

    private static function unlock(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => self::LOCK]);
    }

    /** @return array<string,true> */
    private static function appliedNames(PDO $pdo): array
    {
        $names = [];

        foreach ($pdo->query('SELECT migration_name FROM _migrations') as $row) {
            $names[(string) $row['migration_name']] = true;
        }

        return $names;
    }

    /**
     * @param array<int,string> $directories
     * @param array<string,true> $applied
     * @return array<string,string> name => path, sorted by filename.
     */
    private static function pending(array $directories, array $applied): array
    {
        $files = [];

        foreach ($directories as $directory) {
            foreach (glob(rtrim($directory, '/') . '/*.sql') ?: [] as $path) {
                $name = basename($path);

                if (isset($applied[$name])) {
                    continue;
                }

                $files[$name] = $path;
            }
        }

        ksort($files);

        return $files;
    }
}
