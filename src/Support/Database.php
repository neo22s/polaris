<?php

declare(strict_types=1);

namespace Polaris\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PDO wrapper.
 *
 * Opens a single MySQL connection per request (lazily) and offers small helpers
 * used by the repositories: select(), selectOne(), execute(), insert(), update()
 * and transaction(). All queries use prepared statements with named placeholders.
 *
 * Note: PDO is configured with ATTR_EMULATE_PREPARES = false (native prepares),
 * so a named placeholder may NOT be reused twice in the same statement.
 */
final class Database
{
    /** Lazily-created PDO connection, shared for the whole request. */
    private ?PDO $pdo = null;

    /** @param array<string,mixed> $config Database credentials (host, port, name, user, pass, persistent). */
    public function __construct(private array $config)
    {
    }

    /**
     * Return the shared PDO connection, opening it on first use.
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
        );

        try {
            $this->pdo = new PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,       // throw on SQL errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,  // rows as associative arrays
                PDO::ATTR_EMULATE_PREPARES => false,               // real server-side prepares
                PDO::ATTR_PERSISTENT => (bool) ($this->config['persistent'] ?? false),
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    /**
     * Prepare and execute a statement, returning the PDOStatement.
     *
     * @param array<string,mixed> $params
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);

        // Bind each value with a real type: native prepares reject a string
        // placeholder in LIMIT/OFFSET, so integers must stay integers.
        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $stmt->bindValue($parameter, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * Run a callback inside a transaction and commit it. Any exception rolls
     * back and is re-thrown.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            $savepoint = 'sp_' . bin2hex(random_bytes(6));
            $pdo->exec('SAVEPOINT ' . $savepoint);

            try {
                $result = $callback();
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);

                return $result;
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                throw $e;
            }
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollbackIfActive($pdo);

            throw $e;
        }
    }

    /**
     * A callback may have committed or rolled back the transaction itself, so
     * a failure path has to tolerate a rollback that is no longer possible.
     */
    private function rollbackIfActive(\PDO $pdo): void
    {
        try {
            $pdo->rollBack();
        } catch (\PDOException) {
            // Already finished by the callback; the exception below is the one
            // that matters and is rethrown by the caller.
        }
    }

    /**
     * Run a query and return all rows.
     *
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Run a query and return the first row, or null.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Run a write query and return the number of affected rows.
     *
     * @param array<string,mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Insert a row from an associative array and return its new id.
     * Column names become the placeholders (":name").
     *
     * An empty row is a programming error, not a no-op: returning 0 would look
     * like a valid id and hide the bug at the call site. On a table without an
     * auto-increment column MySQL also reports 0, which is documented here so a
     * caller can tell the two apart.
     *
     * @param array<string,mixed> $data
     * @throws \InvalidArgumentException When the row is empty or the table/column name is not a plain identifier.
     */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot insert an empty row.');
        }

        $this->assertIdentifier($table, 'table');

        $columns = array_keys($data);
        foreach ($columns as $column) {
            $this->assertIdentifier($column, 'column');
        }

        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $this->run($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Update rows. `$where` is a raw SQL fragment that should reference the
     * placeholders supplied in `$whereParams` (e.g. "id = :where_id").
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $whereParams
     * @throws \InvalidArgumentException When the table or a column name is not a plain identifier.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }

        $this->assertIdentifier($table, 'table');

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier($column, 'column');
        }

        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($data)));

        return $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s', $table, $sets, $where),
            array_merge($data, $whereParams),
        );
    }

    /**
     * Table and column names cannot be bound as parameters, so they are checked
     * against a plain identifier pattern before they are spliced into the SQL.
     * This keeps the guarantee local to the data layer instead of relying on
     * every caller to pass a literal.
     *
     * @throws \InvalidArgumentException
     */
    private function assertIdentifier(string $name, string $kind): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid %s name: %s', $kind, $name));
        }
    }
}
