<?php
declare(strict_types=1);
foreach (['OpException', 'Spec', 'Paths', 'Proc', 'InheritedVhost'] as $class) require dirname(__DIR__) . '/agent/lib/' . $class . '.php';
require dirname(__DIR__) . '/agent/ops/Vhost.php';
use Pjas\Agentd\InheritedVhost;
use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Ops\Vhost;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "ok {$label}\n"; }
function rejects(callable $f): void { try { $f(); } catch (OpException) { return; } throw new RuntimeException('拒否されませんでした'); }
$original = <<<'CONF'
# Unknown modules and directives must survive.
<IfModule mod_ssl.c>
<VirtualHost *:443>
ServerName chat.example.com
DocumentRoot "/var/www/pjas-chat/dist"
<Directory "/var/www/pjas-chat/dist">
Require all granted
FallbackResource /index.html
UnknownCustomDirective "keep me"
</Directory>
<Location /private>
Require ip 10.0.0.0/8
</Location>
ProxyPass /ws ws://127.0.0.1:8081
ProxyPassMatch ^/api(?:/.*)?$ unix:/run/php/php8.5-fpm.sock|fcgi://localhost/var/www/pjas-chat/api/index.php
SetEnv DATA /var/www/pjas-chat/shared/db.sqlite
SSLEngine on
SSLCertificateFile /old/cert.pem
SSLCertificateKeyFile /old/key.pem
SSLCertificateChainFile /old/chain.pem
</VirtualHost>
</IfModule>
CONF;
$spec = ['name' => 'pjas-chat', 'domain' => 'chat.example.com', 'docroot' => 'dist', 'runtime' => 'php8.5-fpm', 'tls' => 1, 'allow_override' => 'None', 'extra_conf' => ''];
$out = InheritedVhost::render($original, $spec, '/var/www/pjas-chat/current', '/new/cert.pem', '/new/key.pem');
foreach (['UnknownCustomDirective "keep me"', 'Require ip 10.0.0.0/8', 'ProxyPass /ws ws://127.0.0.1:8081',
    'DocumentRoot /var/www/pjas-chat/current/dist', '<Directory "/var/www/pjas-chat/current/dist">',
    'fcgi://localhost/var/www/pjas-chat/current/api/index.php', '/var/www/pjas-chat/shared/db.sqlite',
    'SSLCertificateFile /new/cert.pem', 'SSLCertificateKeyFile /new/key.pem', '<IfModule mod_ssl.c>'] as $part) check(str_contains($out, $part), '継承: ' . $part);
check(!str_contains($out, '/old/') && !str_contains($out, '/current/current'), '競合する証明書と重複パスを除去');
rejects(fn() => InheritedVhost::render($original . "\n<VirtualHost *:80>\nServerName other.example.com\n</VirtualHost>", $spec, '/var/www/pjas-chat/current', '/c', '/k'));
rejects(fn() => InheritedVhost::render($original, array_replace($spec, ['tls' => 0]), '/var/www/pjas-chat/current', '/c', '/k'));
$http = "<VirtualHost *:80>\nServerName chat.example.com\nDocumentRoot /var/www/pjas-chat/dist\nUnknownCustomDirective keep\n</VirtualHost>\n";
$https = InheritedVhost::render($http, $spec, '/var/www/pjas-chat/current', '/c', '/k');
check(str_contains($https, '<VirtualHost *:443>') && substr_count($https, 'UnknownCustomDirective keep') === 2, 'HTTPのみの設定には独自設定を保持したHTTPSを追加');
$dir = sys_get_temp_dir() . '/pjas-inherit-' . bin2hex(random_bytes(6));
mkdir($dir); mkdir($dir . '/available'); mkdir($dir . '/enabled');
$fake = $dir . '/apache';
file_put_contents($fake, "#!/bin/sh\nexit 0\n"); chmod($fake, 0700);
file_put_contents($dir . '/available/chat.conf', $original);
$cfg = ['apache_sites' => $dir . '/available', 'apache_sites_enabled' => $dir . '/enabled', 'a2ensite' => $fake, 'a2dissite' => $fake, 'apachectl' => $fake];
$vhost = new Vhost($cfg, new Paths('/var/www'));
try {
    $preview = $vhost->preview(['service' => $spec, 'migration_source' => 'chat.conf']);
    check($preview['rendered'] === $out || str_contains($preview['rendered'], 'UnknownCustomDirective'), '移行プレビューに独自設定を表示');
    rejects(fn() => $vhost->apply(['service' => $spec, 'migration_source' => 'chat.conf', 'migration_hash' => 'bad']));
    rejects(fn() => $vhost->preview(['service' => $spec, 'migration_source' => '../chat.conf']));
    file_put_contents($dir . '/available/chat.conf', $original . "\n# modified\n");
    rejects(fn() => $vhost->apply(['service' => $spec, 'migration_source' => 'chat.conf', 'migration_hash' => $preview['migration_hash']]));
    file_put_contents($dir . '/available/chat.conf', $original);
    chmod($dir . '/available/chat.conf', 0666);
    rejects(fn() => $vhost->preview(['service' => $spec, 'migration_source' => 'chat.conf']));
    chmod($dir . '/available/chat.conf', 0644);
    check(true, '元設定の変更と書き込み可能な移行元を拒否');
    file_put_contents($fake, "#!/bin/sh\nif [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");
    rejects(fn() => $vhost->apply(['service' => $spec, 'migration_source' => 'chat.conf', 'migration_hash' => $preview['migration_hash']]));
    check(!is_file($dir . '/available/.pjas-inherited-pjas-chat.json') && !is_file($dir . '/available/pjas-pjas-chat.conf'), 'configtest失敗時は継承を確定せず復元');
    file_put_contents($fake, "#!/bin/sh\nexit 0\n");
    $vhost->apply(['service' => $spec, 'migration_source' => 'chat.conf', 'migration_hash' => $preview['migration_hash']]);
    check($vhost->preview(['service' => $spec])['inherited'], '反映成功時に継承元を保存');
    $renamed = $vhost->render(array_replace($spec, ['domain' => 'new.example.com']));
    check(str_contains($renamed, 'ServerName new.example.com'), '継承後の公開アドレス変更にも追従');
    unlink($dir . '/available/chat.conf');
    $next = $vhost->render(array_replace($spec, ['docroot' => 'public']));
    check(str_contains($next, 'UnknownCustomDirective') && str_contains($next, 'DocumentRoot /var/www/pjas-chat/current/public'), '元ファイル削除後も継承設定と公開フォルダ変更を維持');
    rejects(fn() => $vhost->preview(['service' => $spec, 'migration_source' => 'chat.conf']));
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
