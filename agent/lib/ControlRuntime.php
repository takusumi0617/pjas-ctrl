<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** Convert only the control panel frontend; production site vhosts are never passed here. */
final class ControlRuntime
{
    public static function frontend(string $body): string
    {
        if(!preg_match('~<VirtualHost\s~i',$body))throw new \RuntimeException('管理画面のVirtualHost設定がありません');
        $updated=preg_replace('~<FilesMatch[^>]*>\s*SetHandler\s+"proxy:unix:/run/php/[^"|]+\|fcgi://localhost"\s*</FilesMatch>~is','',$body);
        if(preg_match('~SetHandler\s+[^\r\n]*proxy:unix:~i',$updated))throw new \RuntimeException('管理画面に標準外のPHPハンドラーがあります。systemd/pjas-ctrl.apache.confのFilesMatch構成に合わせてから再実行してください');
        if(!str_contains($updated,'http://127.0.0.1:8184/'))$updated=preg_replace('~</VirtualHost>~i',"    ProxyPreserveHost On\n    ProxyPass / http://127.0.0.1:8184/ connectiontimeout=5 timeout=900\n    ProxyPassReverse / http://127.0.0.1:8184/\n</VirtualHost>",$updated);
        if(!str_contains($updated,'<LocationMatch "^/hooks/github$">'))$updated=preg_replace('~</VirtualHost>~i',"    <LocationMatch \"^/hooks/github$\">\n        Require all granted\n        LimitRequestBody 26214400\n    </LocationMatch>\n</VirtualHost>",$updated);
        return $updated;
    }
}
