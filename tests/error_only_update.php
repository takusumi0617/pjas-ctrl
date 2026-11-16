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
    ProxyPassMatch ^/api/(.*)$ unix:/run/php/php8.5-fpm.sock|fcgi://localhost/var/www/chat/current/api/index.php
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
    $p=['service'=>$s,'update_mode'=>'auto'];
    $preview=$v->preview($p);
    check($preview['resolved_mode']==='errors' && $preview['can_apply'],'既存confがあれば統合ボタンは自動で部分更新を選ぶ');
    check(file_get_contents($path)===$original,'プレビューはconfを書き換えず継承元も読み込まない');
    check(str_replace(SiteErrors::directives(),'',$preview['rendered'])===$original,'80と443の独自設定が生成部分以外はバイト単位で一致');
    $v->apply($p+['current_hash'=>$preview['current_hash']]);
    $updated=file_get_contents($path);
    check($updated===$preview['rendered'],'プレビューと同じ内容を適用（a2ensite/a2dissiteは呼ばない）');
    check(file_get_contents($snapshot)==='old snapshot deliberately not read by errors mode','エラーページ更新で継承元を変更しない');
    check(SiteErrors::decorate($updated)===$updated,'繰り返しても生成部分が重複しない');
    $historyRepo=$dir.'/.pjas-history/chat';
    chmod($historyRepo,0755);
    check(!$v->preview($p)['can_apply'],'Git履歴が安全に保存できなければ反映ボタンを出さない');
    rejects(fn()=>$v->apply($p+['current_hash'=>hash('sha256',$updated)]));
    check(file_get_contents($path)===$updated,'Git履歴の保存失敗時はconfを変更しない');
    chmod($historyRepo,0700);
    $legacy=str_replace("    # end pjas-ctrl standard error pages\n",'',$updated);
    check(SiteErrors::decorate($legacy)===$updated,'旧版のマーカーにも対応し重複せず更新');
    rejects(fn()=>$v->apply($p+['current_hash'=>$preview['current_hash']]));
    check(file_get_contents($path)===$updated,'プレビュー後に変更されたconfを上書きしない');
    file_put_contents($path,$original."\n# later edit\n");
    $preview=$v->preview($p);touch($dir.'/fail');
    rejects(fn()=>$v->apply($p+['current_hash'=>$preview['current_hash']]));
    check(file_get_contents($path)===$original."\n# later edit\n",'設定検査失敗時は手作業の追記も含めて完全復元');
    rejects(fn()=>$v->apply(['service'=>$s,'update_mode'=>'invalid']));
    unlink($dir.'/fail');
    $afterFail=$dir.'/after-fail';
    file_put_contents($afterFail,"#!/bin/sh\nif [ \"\$1\" = graceful ]; then chmod 755 '".$historyRepo."'; fi\nexit 0\n");
    chmod($afterFail,0700);
    $warningVhost=new Vhost(array_replace($cfg,['apachectl'=>$afterFail]),new Paths('/var/www'));
    $warning=$warningVhost->apply($p+['current_hash'=>hash('sha256',file_get_contents($path))]);
    check(!empty($warning['history_warning'])&&str_contains(file_get_contents($path),'ErrorDocument 404'),'反映後だけの履歴保存失敗は、設定を反映済みとして警告');
    chmod($historyRepo,0700);
    $broken=preg_replace('/^[\t ]*ErrorDocument 404 [^\r\n]*\r?\n/m', '', SiteErrors::decorate($original));
    file_put_contents($path,$broken);
    $brokenPreview=$v->preview($p);
    check(!$brokenPreview['errors_available'] && $brokenPreview['preview_error']!=='' && $brokenPreview['current']===$broken,'生成部分が不完全でも現在のconfを検査表示でき、反映は止める');
    unlink($path);
    // 既存設定を確認した後にファイルが消えても、全体作成へ切り替えない。
    rejects(fn()=>$v->apply($p+['current_hash'=>hash('sha256',$broken)]));
    check(!file_exists($path),'確認後にconfが消えた場合は再生成せず拒否');
    unlink($snapshot);
    $initial=$v->preview($p);
    check($initial['resolved_mode']==='template' && $initial['can_apply'],'confがない場合は初回作成をプレビュー');
    file_put_contents($path,$original);
    rejects(fn()=>$v->apply($p+['current_hash'=>$initial['current_hash']]));
    check(file_get_contents($path)===$original,'初回プレビュー後にconfが作成されたら上書きせず拒否');
    unlink($path);
    $creator=new Vhost(array_replace($cfg,['a2ensite'=>$apache]),new Paths('/var/www'));
    $creator->apply($p+['current_hash'=>$initial['current_hash']]);
    check(str_contains(file_get_contents($path),'ErrorDocument 404'),'統合ボタンで初回の公開設定とエラーページを作成');
    // 自サイトの設定がないときも、別ファイルの80/443を暗黙に置き換えない。
    unlink($path); mkdir($dir.'/enabled');
    file_put_contents($dir.'/enabled/legacy.conf',"<VirtualHost *:443>\nServerName chat.example.com\n</VirtualHost>\n");
    $conflict=new Vhost(array_replace($cfg,['apache_sites_enabled'=>$dir.'/enabled']),new Paths('/var/www'));
    check(!$conflict->preview($p)['can_apply'],'既存の別confがあれば継承確認前の新規作成を止める');
    rejects(fn()=>$conflict->apply($p+['current_hash'=>hash('sha256','')]));
    check(!file_exists($path),'既存の別confを無効化する生成処理には進まない');
    unlink($dir.'/enabled/legacy.conf'); rmdir($dir.'/enabled');
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
