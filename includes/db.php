<?php
/**
 * PDO database access. Prepared statements only.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        env('db.host', 'localhost'), (int) env('db.port', 3306), env('db.name', ''));
    $pdo = new PDO($dsn, (string) env('db.user', ''), (string) env('db.pass', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    // Keep MySQL and PHP on the same clock (SAST has no daylight saving)
    $pdo->exec("SET time_zone = '+02:00', SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_val(string $sql, array $params = []): mixed
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_col(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);
    return (int) db()->lastInsertId();
}

/** Build "?, ?, ?" for IN() clauses. */
function db_placeholders(array $items): string
{
    return implode(',', array_fill(0, max(1, count($items)), '?'));
}

/**
 * Run $fn inside a transaction. Retries on deadlock / lock-wait timeout.
 * Nested calls simply join the outer transaction.
 */
function db_tx(callable $fn, int $retries = 3): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $attempt = 0;
    while (true) {
        $attempt++;
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            if (in_array($code, [1213, 1205], true) && $attempt < $retries) {
                usleep(50000 * $attempt);
                continue;
            }
            throw $e;
        }
    }
}
