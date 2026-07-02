<?php
/**
 * PDO singleton (MySQL).
 *
 *   $db = db();                       // PDO obyekti
 *   $row  = db_one($sql, $params);    // bitta qator (assoc) yoki null
 *   $rows = db_all($sql, $params);    // qatorlar massivi
 *   $val  = db_val($sql, $params);    // birinchi ustun qiymati
 *   $id   = db_insert($sql, $params); // INSERT -> lastInsertId
 *   db_exec($sql, $params);           // UPDATE/DELETE -> ta'sirlangan qatorlar
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require __DIR__ . '/config.php';
    $c   = $cfg['db'];

    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";

    // Belgilar to'plami DSN'dagi charset=utf8mb4 orqali o'rnatiladi,
    // shuning uchun INIT_COMMAND kerak emas (PHP 8.5'da deprecated edi).
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

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

function db_val(string $sql, array $params = [])
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);
    return (int) db()->lastInsertId();
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

/**
 * IN (...) uchun joylashtiruvchi yaratish.
 * Qaytaradi: [ "?,?,?", [v1,v2,v3] ]  yoki bo'sh massiv uchun [ "NULL", [] ].
 */
function db_in(array $values): array
{
    $values = array_values($values);
    if (!$values) {
        return ['NULL', []];
    }
    return [implode(',', array_fill(0, count($values), '?')), $values];
}
