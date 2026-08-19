#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 初回デプロイ時の「既存サイト引き継ぎ」判定 (Deploy::adoptPlan) を確認する。
 * 誤判定は稼働中サイトのファイルを巻き込むため、CI で必ず回す。
 *
 *   php tests/adopt_plan.php
 */

define('PJAS_ROOT', dirname(__DIR__));

require PJAS_ROOT . '/agent/lib/OpException.php';
require PJAS_ROOT . '/agent/lib/Spec.php';
require PJAS_ROOT . '/agent/lib/Paths.php';
require PJAS_ROOT . '/agent/lib/Proc.php';
require PJAS_ROOT . '/agent/lib/JobLog.php';
require PJAS_ROOT . '/agent/ops/Vhost.php';
require PJAS_ROOT . '/agent/ops/Health.php';
require PJAS_ROOT . '/agent/ops/Deploy.php';

use Pjas\Agentd\Ops\Deploy;

$root = sys_get_temp_dir() . '/pjas-adopt-' . bin2hex(random_bytes(4));
$fail = 0;

/** テスト用のサービスディレクトリを組み立てる */
function build(string $base, array $tree): void
{
    mkdir($base, 0755, true);
    foreach ($tree as $path => $kind) {
        $full = $base . '/' . $path;
        if ($kind === 'dir') {
            mkdir($full, 0755, true);
        } elseif ($kind === 'file') {
            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, "x\n");
        } else {                                  // link:<target>
            symlink(substr($kind, 5), $full);
        }
    }
}

function check(string $label, array $got, array $want): void
{
    global $fail;
    $ok = $got['entries'] === $want['entries']
        && $got['flatten'] === $want['flatten']
        && $got['current_link'] === $want['current_link'];
    if ($ok) {
        echo "ok   {$label}\n";
        return;
    }
    $fail++;
    echo "NG   {$label}\n";
    echo '     got  ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo '     want ' . json_encode($want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

// 1. 登録直後の空のサービス: 引き継ぐものは無い
$base = $root . '/empty';
build($base, ['releases' => 'dir', 'shared' => 'dir']);
check('空のサービスは引き継がない', Deploy::adoptPlan($base),
      ['entries' => [], 'flatten' => null, 'current_link' => null]);

// 2. 典型的な既存サイト: releases/ shared/ 以外をすべて引き継ぐ
$base = $root . '/legacy';
build($base, ['releases' => 'dir', 'shared' => 'dir', 'index.php' => 'file',
              '.htaccess' => 'file', 'public' => 'dir', 'vendor' => 'dir']);
check('既存ファイルは管理用ディレクトリを除いて引き継ぐ', Deploy::adoptPlan($base),
      ['entries' => ['.htaccess', 'index.php', 'public', 'vendor'], 'flatten' => null, 'current_link' => null]);

// 3. 単一トップディレクトリ: 中身をリリース直下に上げる (アーカイブ展開と同じ規則)
$base = $root . '/nested';
build($base, ['releases' => 'dir', 'shared' => 'dir', 'billing-app/index.php' => 'file']);
check('単一トップディレクトリは平坦化する', Deploy::adoptPlan($base),
      ['entries' => ['billing-app'], 'flatten' => 'billing-app', 'current_link' => null]);

// 4. current が実ディレクトリ: 引き継ぎ対象に含める (mv -T で上書きできないため)
$base = $root . '/realcurrent';
build($base, ['releases' => 'dir', 'shared' => 'dir', 'current/index.php' => 'file', 'README.md' => 'file']);
check('current が実ディレクトリなら引き継ぐ', Deploy::adoptPlan($base),
      ['entries' => ['README.md', 'current'], 'flatten' => null, 'current_link' => null]);

// 5. current がサービス内を指すリンク: リンクは外し、指し先は引き継ぐ
$base = $root . '/linkinside';
build($base, ['releases' => 'dir', 'shared' => 'dir', 'htdocs/index.php' => 'file']);
symlink($base . '/htdocs', $base . '/current');
check('サービス内を指す current リンクは外す', Deploy::adoptPlan($base),
      ['entries' => ['htdocs'], 'flatten' => 'htdocs', 'current_link' => 'inside']);

// 6. current が外を指すリンク: 判断できないので呼び出し側で中断させる
$base = $root . '/linkoutside';
build($base, ['releases' => 'dir', 'shared' => 'dir']);
symlink(sys_get_temp_dir(), $base . '/current');
check('外を指す current リンクは outside として報告する', Deploy::adoptPlan($base),
      ['entries' => [], 'flatten' => null, 'current_link' => 'outside']);

// 7. 壊れたリンクと切替中の一時リンクは引き継ぎ対象にしない
$base = $root . '/dangling';
build($base, ['releases' => 'dir', 'shared' => 'dir', 'index.php' => 'file']);
symlink($base . '/releases/20260101-000000', $base . '/current');
symlink($base . '/releases/20260101-000000', $base . '/.current-abcd1234');
check('壊れた current と一時リンクは対象外', Deploy::adoptPlan($base),
      ['entries' => ['index.php'], 'flatten' => null, 'current_link' => 'dangling']);

exec('rm -rf ' . escapeshellarg($root));

echo $fail === 0 ? "\nすべて一致しました\n" : "\n{$fail} 件のずれがあります\n";
exit($fail === 0 ? 0 : 1);
