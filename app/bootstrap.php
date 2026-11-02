<?php
declare(strict_types=1);

use Pjas\Support\Config;

define('PJAS_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Pjas\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 5));
    $file = PJAS_ROOT . '/app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load(PJAS_ROOT . '/config.php');

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode','1');
    session_name((string) Config::get('session_name', 'pjasctrl'));
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (bool)Config::get('force_https',true) || (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
        'path'     => '/',
    ]);
    session_start();

    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:");
}
