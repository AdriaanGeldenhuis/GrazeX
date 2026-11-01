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
    $host = trim((string) env('db.host', 'localhost'));
    $hosts = [$host];
    // On an Xneelo managed server MySQL runs on the same machine: if the server name is
    // unreachable from PHP, fall back to the local connection automatically.
    $memo = GX_ROOT . '/storage/cache/db-host-' . substr(hash('sha256', $host), 0, 12) . '.txt';
    if (!in_array(strtolower($host), ['localhost', '127.0.0.1'], true)) {
        $remembered = @is_file($memo) ? trim((string) @file_get_contents($memo)) : '';
        $hosts = in_array($remembered, ['localhost', '127.0.0.1'], true)
            ? [$remembered, $host, $remembered === 'localhost' ? '127.0.0.1' : 'localhost']
            : [$host, 'localhost', '127.0.0.1'];
    }
    $first = null;
    foreach ($hosts as $h) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $h, (int) env('db.port', 3306), env('db.name', ''));
        try {
            $pdo = new PDO($dsn, (string) env('db.user', ''), (string) env('db.pass', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_TIMEOUT            => 4,
            ]);
            $GLOBALS['GX_DB_HOST_USED'] = $h;
            if ($h !== $host && !@is_file($memo)) {
                @mkdir(dirname($memo), 0775, true);
                @file_put_contents($memo, $h);
            } elseif ($h === $host && @is_file($memo)) {
                @unlink($memo);
            }
            break;
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode();
            // Keep MySQL's own words for /setup/ (the DB username is masked there)
            $GLOBALS['GX_DB_ATTEMPTS'][$h] = ['code' => $code, 'msg' => preg_replace('/^SQLSTATE\[\w+\]\s*(\[\d+\]\s*)?/', '', $e->getMessage())];
            $pdo = null;
            if (!in_array($code, [2002, 2003, 2005, 2006], true)) {
                $first = $e; // a real answer from MySQL (password, access, name) beats "unreachable"
                if ($h === $host) {
                    throw $e; // the configured server answered — trying other hosts will not help
                }
                continue;
            }
            $first ??= $e;
        }
    }
    if (!$pdo instanceof PDO) {
        throw $first;
    }
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
