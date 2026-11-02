<?php
declare(strict_types=1);

namespace Pjas\Support;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $path = (string) Config::get('db');
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            @chmod($path,0600);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return self::$pdo;
    }

    public static function migrate(string $schemaFile): void
    {
        self::conn()->exec(file_get_contents($schemaFile));
        $columns = array_column(self::all('PRAGMA table_info(users)'), 'name');
        foreach (['totp_last_step'=>'INTEGER NOT NULL DEFAULT -1','scope_all'=>'INTEGER NOT NULL DEFAULT 1','enabled'=>'INTEGER NOT NULL DEFAULT 1'] as $name=>$definition) {
            if (!in_array($name,$columns,true)) self::conn()->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $args = []): array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $row = self::all($sql, $args)[0] ?? null;
        return $row;
    }

    public static function run(string $sql, array $args = []): int
    {
        $st = self::conn()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $args = []): int
    {
        self::run($sql, $args);
        return (int) self::conn()->lastInsertId();
    }
}
