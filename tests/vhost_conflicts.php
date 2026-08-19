#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 反映時に自動で無効化する「管理外の重複サイト」の判定を確認する。
 * 誤検知は無関係なサイトを止めてしまうため、CI で必ず回す。
 *
 *   php tests/vhost_conflicts.php
 */

define('PJAS_ROOT', dirname(__DIR__));

require PJAS_ROOT . '/agent/lib/OpException.php';
require PJAS_ROOT . '/agent/lib/Spec.php';
require PJAS_ROOT . '/agent/lib/Paths.php';
require PJAS_ROOT . '/agent/lib/Proc.php';
require PJAS_ROOT . '/agent/ops/Vhost.php';

use Pjas\Agentd\Ops\Vhost;

$root      = sys_get_temp_dir() . '/pjas-conf-' . bin2hex(random_bytes(4));
$available = $root . '/sites-available';
$enabled   = $root . '/sites-enabled';
mkdir($available, 0755, true);
mkdir($enabled, 0755, true);
$fail = 0;

/** sites-available に置き、sites-enabled から相対リンクを張る (a2ensite と同じ形) */
function site(string $name, string $body, bool $enable = true): void
{
    global $available, $enabled;
    file_put_contents("{$available}/{$name}.conf", $body);
    if ($enable) {
        symlink("../sites-available/{$name}.conf", "{$enabled}/{$name}.conf");
    }
}

function check(string $label, array $got, array $want): void
{
    global $fail;
    if ($got === $want) {
        echo "ok   {$label}\n";
        return;
    }
    $fail++;
    echo "NG   {$label}\n";
    echo '     got  ' . json_encode($got) . "\n";
    echo '     want ' . json_encode($want) . "\n";
}

$managed = Vhost::MANAGED_HEADER . "\n# service: billing\n"
    . "<VirtualHost *:80>\n    ServerName billing.example.com\n</VirtualHost>\n";

site('billing',  "<VirtualHost *:80>\n    ServerName billing.example.com\n    DocumentRoot /var/www/billing/public\n</VirtualHost>\n");
site('legacy',   "<VirtualHost *:80>\n    ServerName old.example.com\n    ServerAlias BILLING.EXAMPLE.COM.\n</VirtualHost>\n");
site('other',    "<VirtualHost *:80>\n    ServerName shop.example.com\n</VirtualHost>\n");
site('similar',  "<VirtualHost *:80>\n    ServerName notbilling.example.com\n</VirtualHost>\n");
site('disabled', "<VirtualHost *:80>\n    ServerName billing.example.com\n</VirtualHost>\n", false);
site('pjas-billing', $managed);
site('pjas-mirror',  Vhost::MANAGED_HEADER . "\n<VirtualHost *:80>\n    ServerName billing.example.com\n</VirtualHost>\n");
site('empty', "");

$self = $available . '/pjas-billing.conf';

check('同じ ServerName / ServerAlias の管理外サイトだけを拾う (大小文字と末尾ドットも同一視)',
      Vhost::conflictingSites($enabled, 'billing.example.com', $self),
      ['billing', 'legacy']);

check('別ドメインは対象外',
      Vhost::conflictingSites($enabled, 'shop.example.com', $self),
      ['other']);

check('一致するサイトが無ければ空',
      Vhost::conflictingSites($enabled, 'nowhere.example.com', $self),
      []);

check('sites-enabled が存在しない場合も落ちない',
      Vhost::conflictingSites($root . '/none', 'billing.example.com', $self),
      []);

exec('rm -rf ' . escapeshellarg($root));

echo $fail === 0 ? "\nすべて一致しました\n" : "\n{$fail} 件のずれがあります\n";
exit($fail === 0 ? 0 : 1);
