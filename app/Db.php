<?php
/** Тонкая обёртка над PDO. Запрос API выполняется в одной транзакции (как `with db() as c` в Python-версии). */
class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $h = cfg('db.host', '127.0.0.1');
            $dsn = 'mysql:host=' . $h . ';port=' . (int)cfg('db.port', 3306) . ';dbname=' . cfg('db.name', '') . ';charset=utf8mb4';
            try {
                self::$pdo = new PDO($dsn, cfg('db.user', ''), cfg('db.pass', ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);
            } catch (PDOException $e) {
                error_log('EPD db connect: ' . $e->getMessage());
                throw new ApiError(500, 'Нет связи с базой данных. Проверь config/config.local.php');
            }
            self::$pdo->exec("SET NAMES utf8mb4");
        }
        return self::$pdo;
    }

    public static function begin(): void { if (!self::pdo()->inTransaction()) { self::pdo()->beginTransaction(); } }
    public static function commit(): void { if (self::pdo()->inTransaction()) { self::pdo()->commit(); self::pdo()->beginTransaction(); } }
    public static function finish(bool $ok): void
    {
        if (self::$pdo && self::$pdo->inTransaction()) { $ok ? self::$pdo->commit() : self::$pdo->rollBack(); }
    }

    public static function run(string $sql, array $p = []): int
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s->rowCount();
    }
    public static function one(string $sql, array $p = []): ?array
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }
    public static function all(string $sql, array $p = []): array
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }
    public static function val(string $sql, array $p = [])
    {
        $r = self::one($sql, $p);
        return $r === null ? null : reset($r);
    }
    public static function insertId(): int { return (int)self::pdo()->lastInsertId(); }
}
