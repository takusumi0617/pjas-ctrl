<?php
declare(strict_types=1);

use Pjas\Support\Config;

define('PJAS_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Pjas\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 5));
    $file = str_starts_with($rel, 'Shared/')
        ? PJAS_ROOT . '/shared/' . substr($rel, 7) . '.php'
        : PJAS_ROOT . '/app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load(PJAS_ROOT . '/config.php');

// All ordinary requests and CLI writers wait while the independent updater owns the DB.
$maintenance='/run/pjas-control-update/maintenance';
if(is_file($maintenance)) {
    $cli=PHP_SAPI==='cli';
    $allowed=$cli?(getenv('PJAS_CONTROL_UPDATE')==='1'||basename($_SERVER['argv'][0]??'')==='pjas-control-update'):
        (($_SERVER['REMOTE_ADDR']??'')==='127.0.0.1'&&hash_equals(trim((string)file_get_contents($maintenance)),hash('sha256',(string)($_SERVER['HTTP_X_PJAS_UPDATE_PROBE']??''))));
    if(!$allowed){
        if($cli){fwrite(STDERR,"ctrlを更新中です。完了後に再実行してください。\n");exit(75);}
        http_response_code(503);header('Retry-After: 5');header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="5"><title>ctrlを更新中</title><main><h1>ctrlを更新中です</h1><p>画面を停止して更新・復旧を行っています。5秒ごとに接続を確認します。長時間戻らない場合はサーバーで更新サービスの状態を確認してください。</p><p><a href="/">ホームへ戻る</a></p></main></html>';exit;
    }
}


if (PHP_SAPI !== 'cli') {
    ini_set('display_errors','0');ini_set('log_errors','1');
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
