<?php
declare(strict_types=1);
foreach (['OpException', 'Spec', 'Paths', 'Proc', 'InheritedVhost', 'SitePause'] as $f) require_once dirname(__DIR__) . '/agent/lib/' . $f . '.php';
foreach (['Vhost', 'Health'] as $f) require_once dirname(__DIR__) . '/agent/ops/' . $f . '.php';
use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\SitePause;
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Ops\Health;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "ok {$label}\n"; }
function rejects(callable $f): void { try { $f(); } catch (OpException) { return; } throw new RuntimeException('拒否されませんでした'); }
$dir = sys_get_temp_dir() . '/pjas-pause-' . bin2hex(random_bytes(6));
mkdir($dir); mkdir($dir . '/sites'); mkdir($dir . '/enabled');
$fake = $dir . '/apache'; file_put_contents($fake, "#!/bin/sh\nexit 0\n"); chmod($fake, 0700);
$cfg = ['pause_dir' => $dir . '/pauses', 'apache_sites' => $dir . '/sites', 'apache_sites_enabled' => $dir . '/enabled', 'apachectl' => $fake, 'a2ensite' => $fake, 'a2dissite' => $fake, 'www_root' => $dir . '/www'];
$spec = ['name' => 'chat', 'domain' => 'chat.example.com', 'docroot' => 'dist', 'runtime' => 'static', 'tls' => 1, 'allow_override' => 'None', 'extra_conf' => 'ProxyPass /ws ws://127.0.0.1:8081'];
$v = new Vhost($cfg, new Paths($cfg['www_root']));
$pause = new SitePause($cfg);
try {
    rejects(fn() => $v->pause(['service' => $spec, 'action' => 'pause', 'code' => 404, 'html' => 'missing']));
    $default = $pause->validate(['code'=>404,'page_mode'=>'default','html'=>'ignored']);
    check($default['page_mode']==='default'&&str_contains($default['html'],'ページが見つかりません')&&str_contains($default['html'],'prefers-color-scheme'),'既定ページはコードに応じた案内とダークモードに対応');
    check($pause->validate(['code'=>503,'page_mode'=>'custom','html'=>'<h1>独自ページ</h1>'])['html']==='<h1>独自ページ</h1>','独自HTMLは既定デザインで上書きしない');
    rejects(fn()=>$pause->validate(['code'=>503,'page_mode'=>'invalid']));
    $normal = $v->render($spec);
    file_put_contents($v->confPath('chat'), $normal);
    foreach ([99, 199, 600, "404\nX-Test: value"] as $code) rejects(fn() => $pause->validate(['code' => $code, 'html' => 'x']));
    rejects(fn() => $pause->validate(['code' => 404, 'html' => str_repeat('a', 128 * 1024 + 1)]));
    $html = '<!doctype html><title>Custom 404</title><h1>非公開</h1><style>body{color:red}</style><?php echo "DO NOT EXECUTE";?>';
    $v->pause(['service' => $spec, 'action' => 'pause', 'code' => 404, 'html' => $html]);
    $stopped = file_get_contents($v->confPath('chat'));
    check($v->pauseStatus(['service' => $spec])['enabled'] && $pause->read('chat')['code'] === 404, 'サイト単位にHTTPコードとHTMLを保存');
    check(!str_contains($stopped, 'ProxyPass /ws') && substr_count($stopped, 'RewriteRule !^/?response') === 2, 'HTTPとHTTPSの全URLを停止ページへ切り替え転送を止める');
    check(str_contains($stopped, 'ProxyErrorOverride Off'), '404のHTMLをApache標準エラーで置き換えない');
    check($v->render($spec) === $stopped, '公開設定の再生成でも一時停止を維持');
    check(!(new SitePause($cfg))->read('other')['enabled'], '別サイトには影響しない');
    check((new Health($cfg))->poll($spec, static fn($m) => null), '停止中の公開では通常のヘルスチェックをスキップ');
    // 実際のHTTP応答でコード・本文・キャッシュヘッダーを検証する。
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException($error);
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $pause->dir('chat')], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('HTTPテストを開始できません');
    try {
        $ready = false;
        for ($i = 0; $i < 100; $i++) { $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1); if ($socket) { fclose($socket); $ready = true; break; } usleep(20000); }
        check($ready, 'HTTP応答テストを起動');
        foreach ([200, 302, 404, 410, 503, 599] as $code) {
            $pause->write('chat', ['enabled' => true, 'code' => $code, 'html' => $html]);
            $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
            $response = fopen('http://' . $address . '/response.php', 'r', false, $ctx);
            $headers = implode("\n", stream_get_meta_data($response)['wrapper_data']);
            $body = stream_get_contents($response);fclose($response);
            check(str_contains($headers, ' ' . $code . ' ') && $body === $html && str_contains($headers, 'no-store'), "HTTP {$code} とHTMLをそのまま返しPHPを実行しない");
        }
        foreach ([204, 205, 304] as $code) {
            $pause->write('chat', ['enabled' => true, 'code' => $code, 'html' => $html]);
            $body = file_get_contents('http://' . $address . '/response.php', false, $ctx);
            check($body === '', "HTTP {$code} は本文を返さない");
        }
    } finally { proc_terminate($server); fclose($pipes[0]); proc_close($server); }
    $v->pause(['service' => $spec, 'action' => 'resume']);
    check(file_get_contents($v->confPath('chat')) === $normal && !$pause->read('chat')['enabled'], '再開すると元の公開設定へ復元');
    // 継承済みの独自設定も再開時に保持する。
    $source = "<VirtualHost *:443>\nServerName chat.example.com\nServerAlias alias.example.com\nDocumentRoot /var/www/chat/dist\nUnknownDirective keep\nProxyPass /ws ws://127.0.0.1:8081\n</VirtualHost>\n";
    file_put_contents($dir . '/sites/.pjas-inherited-chat.json', json_encode(['body' => $source, 'domain' => 'chat.example.com']));
    file_put_contents($v->confPath('chat'), $v->render($spec));
    $v->pause(['service' => $spec, 'action' => 'pause', 'code' => 503, 'html' => 'Maintenance']);
    check(str_contains(file_get_contents($v->confPath('chat')), 'ServerAlias alias.example.com'), '停止中も既存の別名ドメインを維持');
    $v->pause(['service' => $spec, 'action' => 'resume']);
    check(str_contains(file_get_contents($v->confPath('chat')), 'UnknownDirective keep'), '再開後も継承元の未知の設定を保持');
    $before = file_get_contents($v->confPath('chat'));
    file_put_contents($fake, "#!/bin/sh\nif [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");
    rejects(fn() => $v->pause(['service' => $spec, 'action' => 'pause', 'code' => 404, 'html' => 'failed']));
    check(file_get_contents($v->confPath('chat')) === $before && !$pause->read('chat')['enabled'], '反映失敗時は公開設定と停止状態を復元');
    file_put_contents($fake, "#!/bin/sh\nexit 0\n");
    $v->pause(['service' => $spec, 'action' => 'pause', 'code' => 404, 'html' => 'keep old HTML']);
    $before = file_get_contents($v->confPath('chat'));
    file_put_contents($fake, "#!/bin/sh\nif [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");
    rejects(fn() => $v->pause(['service' => $spec, 'action' => 'pause', 'code' => 503, 'html' => 'changed']));
    check($pause->read('chat')['code'] === 404 && $pause->read('chat')['html'] === 'keep old HTML', '停止ページ変更の失敗時は以前のコードとHTMLへ復元');
    rejects(fn() => $v->pause(['service' => $spec, 'action' => 'resume']));
    check($pause->read('chat')['enabled'] && file_get_contents($v->confPath('chat')) === $before, '再開失敗時は一時停止を維持');

} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
