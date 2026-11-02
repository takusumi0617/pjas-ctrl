<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\ControlRuntime;
$old=file_get_contents(dirname(__DIR__).'/systemd/pjas-ctrl.apache.conf');$new=ControlRuntime::frontend($old);
check(str_contains($new,'Require ip 10.0.0.0/8')&&str_contains($new,'ServerName ctrl.pjas.example')&&str_contains($new,'SSLCertificateFile    /etc/ssl/cloudflare/memo.takusumi.work.pem'),'管理画面を分離しても公開アドレス・TLS・アクセス元制限を保持する');
check(!str_contains($new,'SetHandler "proxy:unix:')&&str_contains($new,'ProxyPass / http://127.0.0.1:8184/'),'管理画面だけを専用HTTP・PHPサービスへ転送する');
check(str_contains($new,'<LocationMatch "^/hooks/github$">')&&substr_count($new,'Require all granted')===2,'GitHubの受信URLだけを追加のアクセス制限の例外にする');
check(ControlRuntime::frontend($new)===$new,'初回準備を繰り返しても転送設定・Webhook例外を重複しない');rejects(fn()=>ControlRuntime::frontend('<Directory /opt/pjas-ctrl/public>Require all granted</Directory>'));
rejects(fn()=>ControlRuntime::frontend(str_replace('SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"','SetHandler "proxy:unix:/custom/php.sock|fcgi://localhost"',$old)));check(true,'標準外のPHPハンドラーは自動的に消さず、適用を止める');
