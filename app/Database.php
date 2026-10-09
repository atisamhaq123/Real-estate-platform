<?php
namespace Aurelle;
use PDO;
final class Database {
    private static ?PDO $connection = null;
    public static function connection(): PDO {
        return self::$connection ??= new PDO('mysql:host='.\env('DB_HOST','127.0.0.1').';port='.\env('DB_PORT','3306').';dbname='.\env('DB_DATABASE','aurelle_estates').';charset=utf8mb4', \env('DB_USERNAME','root'), \env('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone = '+00:00', default_storage_engine = InnoDB"]);
    }
    public static function query(string $sql, array $params = []): \PDOStatement { $stmt=self::connection()->prepare($sql); $stmt->execute($params); return $stmt; }
    public static function all(string $sql, array $params = []): array { return self::query($sql,$params)->fetchAll(); }
    public static function one(string $sql, array $params = []): ?array { return self::query($sql,$params)->fetch() ?: null; }
    public static function insert(string $sql, array $params = []): int { self::query($sql,$params); return (int)self::connection()->lastInsertId(); }
    public static function transaction(callable $callback): mixed {
        $db=self::connection(); $db->beginTransaction();
        try { $result=$callback(); $db->commit(); return $result; } catch (\Throwable $error) { $db->rollBack(); throw $error; }
    }
}
