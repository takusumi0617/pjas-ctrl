<?php
declare(strict_types=1);
// DBやエージェントが使えない障害時も、HTTPコードを保って単独表示できること。
require dirname(__DIR__) . '/app/Http/View.php';
require dirname(__DIR__) . '/app/Http/Router.php';
use Pjas\Http\View;
use Pjas\Http\Router;
$results = [];
foreach ([403, 404, 419, 500] as $code) {
    ob_start();
    View::error($code);
    $html = ob_get_clean();
    if (http_response_code() !== $code || !str_contains($html, '<!doctype html>') || !str_contains($html, 'HTTP ' . $code)) {
        throw new RuntimeException('エラーページとHTTPコードが一致しません: ' . $code);
    }
    $results[] = 'ok 独立したエラーページ HTTP ' . $code;
}
ob_start();
View::error(404, '<script>alert("x")</script>');
$html = ob_get_clean();
if (str_contains($html, '<script>') || !str_contains($html, '&lt;script&gt;')) {
    throw new RuntimeException('エラーメッセージがエスケープされていません');
}
$results[] = 'ok エラーメッセージのHTMLを無害化';
ob_start();
(new Router())->dispatch('GET', '/missing');
$html = ob_get_clean();
if (http_response_code() !== 404 || !str_contains($html, 'ページが見つかりません')) {
    throw new RuntimeException('未登録URLの404が表示されません');
}
$results[] = 'ok 未登録URLも新しい404で応答';
echo implode("\n", $results) . "\n";
