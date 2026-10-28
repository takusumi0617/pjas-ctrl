<?php
declare(strict_types=1);
foreach (['OpException','InheritedVhost','Spec','Paths','Proc'] as $f) require dirname(__DIR__).'/agent/lib/'.$f.'.php';
require dirname(__DIR__).'/agent/ops/Vhost.php';
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Paths;
use Pjas\Agentd\OpException;
use Pjas\Shared\SiteErrors;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "ok {$label}\n"; }
function rejects(callable $fn): void { try { $fn(); } catch (OpException|InvalidArgumentException) { return; } throw new RuntimeException('拒否されませんでした'); }
$dir=sys_get_temp_dir().'/pjas-error-update-'.bin2hex(random_bytes(6));mkdir($dir);
$apache=$dir.'/apache';
file_put_contents($apache,"#!/bin/sh\nif [ -f '".$dir."/fail' ] && [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");chmod($apache,0700);
$cfg=['apache_sites'=>$dir,'apachectl'=>$apache,'a2ensite'=>'/bin/false','a2dissite'=>'/bin/false','pause_dir'=>$dir.'/pauses'];
$v=new Vhost($cfg,new Paths('/var/www'));
$s=['name'=>'chat','domain'=>'chat.example.com','docroot'=>'dist','runtime'=>'static','tls'=>1,'allow_override'=>'None','extra_conf'=>''];
$original=<<<'CONF'
# managed by pjas-ctrl -- do not edit by hand
<VirtualHost *:80>
    ServerName chat.example.com
    Redirect permanent / https://chat.example.com/
</VirtualHost>
<VirtualHost *:443>
    ServerName chat.example.com
    DocumentRoot /var/www/chat/current/dist
    SSLEngine on
    SSLCertificateFile /etc/ssl/cloudflare/memo.takusumi.work.pem
    SSLCertificateKeyFile /etc/ssl/cloudflare/memo.takusumi.work.key
    ProxyPass /ws ws://127.0.0.1:8081
    ProxyPassReverse /ws ws://127.0.0.1:8081
    ProxyPassMatch ^/api/(.*)$ unix:/run/php/php8.4-fpm.sock|fcgi://localhost/var/www/chat/current/api/index.php
    <Directory /var/www/chat/current/dist>
        FallbackResource /index.html
    </Directory>
    Header always set X-Manual-Change KEEP
    ErrorDocument 404 /custom.html
</VirtualHost>
CONF;
$path=$v->confPath('chat');$snapshot=$dir.'/.pjas-inherited-chat.json';
file_put_contents($snapshot,'old snapshot deliberately not read by errors mode');
file_put_contents($path,$original);
try {
    $p=['service'=>$s,'update_mode'=>'errors'];
    $preview=$v->preview($p);
    check(file_get_contents($path)===$original,'プレビューはconfを書き換えず継承元も読み込まない');
    check(str_replace(SiteErrors::directives(),'',$preview['rendered'])===$original,'80と443の独自設定が生成部分以外はバイト単位で一致');
    $v->apply($p+['current_hash'=>$preview['current_hash']]);
    $updated=file_get_contents($path);
    check($updated===$preview['rendered'],'プレビューと同じ内容を適用（a2ensite/a2dissiteは呼ばない）');
    check(file_get_contents($snapshot)==='old snapshot deliberately not read by errors mode','エラーページ更新で継承元を変更しない');
    check(SiteErrors::decorate($updated)===$updated,'繰り返しても生成部分が重複しない');
    $legacy=str_replace("    # end pjas-ctrl standard error pages\n",'',$updated);
    check(SiteErrors::decorate($legacy)===$updated,'旧版のマーカーにも対応し重複せず更新');
    rejects(fn()=>$v->apply($p+['current_hash'=>$preview['current_hash']]));
    check(file_get_contents($path)===$updated,'プレビュー後に変更されたconfを上書きしない');
    file_put_contents($path,$original."\n# later edit\n");
    $preview=$v->preview($p);touch($dir.'/fail');
    rejects(fn()=>$v->apply($p+['current_hash'=>$preview['current_hash']]));
    check(file_get_contents($path)===$original."\n# later edit\n",'設定検査失敗時は手作業の追記も含めて完全復元');
    rejects(fn()=>$v->apply(['service'=>$s,'update_mode'=>'invalid']));
    $broken=preg_replace('/^[\t ]*ErrorDocument 404 [^\r\n]*\r?\n/m', '', SiteErrors::decorate($original));
    file_put_contents($path,$broken);
    $brokenPreview=$v->preview($p);
    check(!$brokenPreview['errors_available'] && $brokenPreview['preview_error']!=='' && $brokenPreview['current']===$broken,'生成部分が不完全でも現在のconfを検査表示でき、反映は止める');
    unlink($path);
    rejects(fn()=>$v->apply($p+['current_hash'=>hash('sha256','')]));
    check(!$v->preview($p)['errors_available'],'confがない場合は雛形からの作成を案内');
} finally {
    foreach (glob($dir.'/*') as $f) if (is_file($f)) unlink($f);
    foreach (glob($dir.'/.*') as $f) if (is_file($f)) unlink($f);
    rmdir($dir);
}
