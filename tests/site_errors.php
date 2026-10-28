<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Domain/VhostRenderer.php';
foreach (['OpException', 'InheritedVhost', 'Spec', 'Paths', 'Proc'] as $f) require dirname(__DIR__) . '/agent/lib/' . $f . '.php';
require dirname(__DIR__) . '/agent/ops/Vhost.php';
use Pjas\Shared\SiteErrors;
use Pjas\Domain\VhostRenderer;
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Paths;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo 'ok ' . $label . "\n"; }
$directives = SiteErrors::directives();
foreach (SiteErrors::MESSAGES as $code => [$title]) {
    check(str_contains($directives, 'ErrorDocument ' . $code . ' "<!doctype html>'), 'HTTP ' . $code . 'に単独表示できる静的ページを指定');
    check(str_contains(SiteErrors::html($code), $title), 'HTTP ' . $code . 'の日本語案内');
}
check(!preg_match('/[^\x00-\x7f]/', $directives), 'Apacheの応答文字コードに依存せず日本語を表示');
check(str_contains(html_entity_decode($directives, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'ページが見つかりません'), '文字参照が日本語に復元できる');
check(max(array_map('strlen', explode("\n", $directives))) < 8190, 'Apache設定の行長制限内に収まる');
$spec = ['name'=>'demo','domain'=>'example.com','runtime'=>'static','docroot'=>'','allow_override'=>'None','extra_conf'=>'ErrorDocument 404 /custom.html','tls'=>0];
$normal = VhostRenderer::render($spec);
check(strpos($normal, 'ErrorDocument 404 "') < strpos($normal, 'ErrorDocument 404 /custom.html'), 'サイト独自のErrorDocumentを後に置いて優先');
check(!str_contains($normal, 'ErrorDocument 503 /maintenance.html'), '旧メンテナンスファイルがないサイトでも503のページが存在');
$dir = sys_get_temp_dir() . '/pjas-errors-' . bin2hex(random_bytes(6));
mkdir($dir);
try {
    $original = "<VirtualHost *:80>\nServerName example.com\nDocumentRoot /var/www/demo\nProxyPass /ws ws://127.0.0.1:8081\nErrorDocument 404 /custom.html\n</VirtualHost>\n";
    file_put_contents($dir . '/.pjas-inherited-demo.json', json_encode(['body'=>$original,'domain'=>'example.com']));
    $vhost = new Vhost(['apache_sites'=>$dir, 'pause_dir'=>$dir.'/pauses'], new Paths('/var/www'));
    $rendered = $vhost->render($spec);
    check(str_contains($rendered, 'ErrorDocument 500 "') && str_contains($rendered, 'ProxyPass /ws ws://127.0.0.1:8081'), '継承した独自設定に最新エラーページを追加');
    check(strpos($rendered, 'ErrorDocument 404 "') < strpos($rendered, 'ErrorDocument 404 /custom.html'), '継承元のエラーページも維持');
    check(file_get_contents($dir . '/.pjas-inherited-demo.json') === json_encode(['body'=>$original,'domain'=>'example.com']), 'プレビューで継承元を書き換えない');
} finally {
    unlink($dir . '/.pjas-inherited-demo.json'); rmdir($dir);
}
