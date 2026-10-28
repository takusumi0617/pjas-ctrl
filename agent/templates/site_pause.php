<?php
// HTMLはデータとして出力する。入力されたPHPコードを実行しない。
declare(strict_types=1);
$config = json_decode((string) file_get_contents(__DIR__ . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$code = (int) $config['code'];
http_response_code($code);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && !in_array($code, [204, 205, 304], true)) {
    echo (string) $config['html'];
}
