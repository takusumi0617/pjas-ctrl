<?php
declare(strict_types=1);
require dirname(__DIR__) . '/shared/SiteErrors.php';
use Pjas\Shared\SiteErrors;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "ok {$label}\n"; }
$conf=<<<'CONF'
<VirtualHost *:80>
    ServerName example.com
    # ErrorDocument 404 /not-enabled.html
    Header set X-Dummy "ErrorDocument 500 /not-a-directive.html"
    ErrorDocument 403 /old.html
    errordocument 403 /new.html
    <IfModule mod_alias.c>
        ErrorDocument 404 /conditional.html
    </IfModule>
</VirtualHost>
<VirtualHost *:443>
    ServerName example.com
    ErrorDocument 404 /custom.html
    ErrorDocument 500 /error.html
    ErrorDocument 500 default # use Apache's default
    <Directory /var/www/example>
        ErrorDocument 403 /directory-only.html
    </Directory>
</VirtualHost>
CONF;
$hosts=SiteErrors::coverage($conf);
check(count($hosts)===2 && $hosts[0]['address']==='*:80' && $hosts[1]['address']==='*:443','80と443を別のVirtualHostとして検査');
check($hosts[0]['codes'][404]['status']==='scoped' && $hosts[1]['codes'][404]['status']==='custom','80の条件付き404と443の独自404を混同しない');
check($hosts[0]['codes'][500]['status']==='missing','ヘッダーやコメント内のErrorDocumentを設定と誤認しない');
check($hosts[1]['codes'][500]['status']==='default','重複指定では後のdefaultを優先し実装済みと数えない');
check($hosts[1]['codes'][403]['status']==='scoped','Directory内だけの指定をサイト全体の設定と数えない');
check($hosts[0]['covered']===1 && $hosts[1]['covered']===1,'VirtualHostごとに直接の指定数を集計');
$updated=SiteErrors::coverage(SiteErrors::decorate($conf));
check($updated[0]['codes'][404]['status']==='standard' && $updated[0]['codes'][404]['scoped'],'共通ページ追加後も条件による上書きを表示');
check($updated[1]['codes'][404]['status']==='custom','共通ページより後の独自指定を優先');
check($updated[1]['codes'][500]['status']==='default','既存のdefault指定も維持して未適用として表示');
$complete=SiteErrors::coverage(SiteErrors::decorate("<VirtualHost *:80>\nServerName example.com\n</VirtualHost>\n<VirtualHost *:443>\nServerName example.com\n</VirtualHost>\n"));
check($complete[0]['covered']===count(SiteErrors::MESSAGES) && $complete[1]['covered']===count(SiteErrors::MESSAGES),'共通ページの全対象コードが80と443の両方に存在');
$broken=preg_replace('/^[\t ]*ErrorDocument 404 [^\r\n]*\r?\n/m','',SiteErrors::decorate("<VirtualHost *:443>\nServerName example.com\n</VirtualHost>\n"));
check(SiteErrors::coverage($broken)[0]['codes'][404]['status']==='missing','生成部分から1行抜けた場合も未指定を検出');
check(SiteErrors::coverage('')===[],'VirtualHostがない設定は確認済みと扱わない');
