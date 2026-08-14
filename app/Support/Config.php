<?php
declare(strict_types=1);

namespace Pjas\Support;

final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException("config.php がありません: {$file} (config.php.example をコピーしてください)");
        }
        self::$data = require $file;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }
}
