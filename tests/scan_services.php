#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 一括登録の候補一覧 (service.scan) を確認する。
 * 推測値は画面で修正できるが、候補の取りこぼしと誤検出は移行時に直接効くのでテストする。
 *
 *   php tests/scan_services.php
 */

define('PJAS_ROOT', dirname(__DIR__));

foreach (['lib/OpException', 'lib/Spec', 'lib/Paths', 'lib/Proc', 'lib/JobLog',
          'ops/Vhost', 'ops/Health', 'ops/Deploy', 'ops/Service'] as $f) {
    require PJAS_ROOT . '/agent/' . $f . '.php';
}

use Pjas\Agentd\Ops\Service;
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Paths;

$root  = sys_get_temp_dir() . '/pjas-scan-' . bin2hex(random_bytes(4));
$www   = $root . '/www';
$sites = $root . '/sites-available';
mkdir($www, 0755, true);
mkdir($sites, 0755, true);
$fail = 0;

function put(string $path, string $body = "x\n"): void
{
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $body);
}

// PHP アプリ + 既存 vhost あり
put("{$www}/billing/public/index.php", "<?php\n");
put("{$sites}/billing.conf", "<VirtualHost *:80>\n    ServerName billing.example.com\n    DocumentRoot /var/www/billing/public\n</VirtualHost>\n");
// 静的サイト。vhost は無い
put("{$www}/docs/index.html", "<h1>docs</h1>\n");
// 一段深い構成 (単一トップディレクトリ)
put("{$www}/nested/app/public/index.php", "<?php\n");
// 既に登録済み (known で除外される)
put("{$www}/portal/index.php", "<?php\n");
// 名前が使えない
put("{$www}/Legacy.Site/index.php", "<?php\n");
// 空のフォルダ
mkdir("{$www}/empty", 0755, true);
// 管理下のディレクトリを模したもの
mkdir("{$www}/managed/releases", 0755, true);
mkdir("{$www}/managed/shared", 0755, true);
// シンボリックリンクは対象外
symlink("{$www}/docs", "{$www}/docs-link");

// vhost の DocumentRoot はテスト用のパスに合わせる
file_put_contents("{$sites}/billing.conf",
    "<VirtualHost *:80>\n    ServerName BILLING.example.com.\n    DocumentRoot {$www}/billing/public\n</VirtualHost>\n");
// 管理下の設定は無視されること
file_put_contents("{$sites}/pjas-docs.conf",
    Vhost::MANAGED_HEADER . "\n<VirtualHost *:80>\n    ServerName wrong.example.com\n    DocumentRoot {$www}/docs\n</VirtualHost>\n");

$svc = new Service(['www_root' => $www, 'apache_sites' => $sites,
                    'web_user' => 'nobody', 'web_group' => 'nogroup'], new Paths($www));
$r = $svc->scan(['known' => ['portal']]);
$by = [];
foreach ($r['services'] as $row) {
    $by[$row['name']] = $row;
}

function check(string $label, mixed $got, mixed $want): void
{
    global $fail;
    if ($got === $want) {
        echo "ok   {$label}\n";
        return;
    }
    $fail++;
    echo "NG   {$label}\n";
    echo '     got  ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '     want ' . json_encode($want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

check('登録済みとシンボリックリンクを除いた候補が並ぶ', array_keys($by),
      ['Legacy.Site', 'billing', 'docs', 'empty', 'managed', 'nested']);

check('PHP アプリは php8.3-fpm と推測する', $by['billing']['runtime'], 'php8.3-fpm');
check('公開フォルダに public を見つける', $by['billing']['docroot'], 'public');
check('既存 vhost から公開アドレスを拾う (大小文字と末尾ドットは正規化)',
      $by['billing']['domain'], 'billing.example.com');

check('静的サイトは static と推測する', $by['docs']['runtime'], 'static');
check('管理下の設定からはアドレスを拾わない', $by['docs']['domain'], '');

check('単一トップディレクトリの中を見て公開フォルダを推測する', $by['nested']['docroot'], 'public');

check('使えない名前は登録不可として報告する', $by['Legacy.Site']['eligible'], false);
check('使える名前は登録可', $by['billing']['eligible'], true);

check('空のフォルダは中身 0 件', $by['empty']['files'], 0);
check('管理用フォルダの有無を報告する', $by['managed']['managed'], true);
check('引き継げる中身の数を数える', $by['billing']['files'], 1);

exec('rm -rf ' . escapeshellarg($root));

echo $fail === 0 ? "\nすべて一致しました\n" : "\n{$fail} 件のずれがあります\n";
exit($fail === 0 ? 0 : 1);
