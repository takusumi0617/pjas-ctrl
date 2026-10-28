#!/usr/bin/env php
<?php
declare(strict_types=1);

/** agentが操作する永続領域がsystemdの書き込み許可に含まれることを確認する。 */

$unit = (string) file_get_contents(dirname(__DIR__) . '/systemd/pjas-agentd.service');
$required = [
    '/var/www',
    '/var/lib/pjas-repositories',
    '/var/lib/pjas-pauses',
    '/etc/apache2/sites-available',
    '/etc/apache2/sites-enabled',
    '/var/lib/apache2/site',
    '/etc/cron.d',
    '/var/log/pjas',
    '/var/lib/pjas',
];
$fail = 0;
foreach ($required as $path) {
    if (str_contains($unit, $path)) {
        echo "ok   書き込み許可: {$path}\n";
    } else {
        $fail++;
        echo "FAIL 書き込み許可がありません: {$path}\n";
    }
}
exit($fail === 0 ? 0 : 1);
