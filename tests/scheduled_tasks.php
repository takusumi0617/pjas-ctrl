#!/usr/bin/env php
<?php
declare(strict_types=1);

/** 定期処理のcronファイルと実行スクリプトが安全に分離されることを確認する。 */

define('PJAS_ROOT', dirname(__DIR__));
require PJAS_ROOT . '/agent/lib/OpException.php';
require PJAS_ROOT . '/agent/lib/Spec.php';
require PJAS_ROOT . '/agent/lib/Paths.php';
require PJAS_ROOT . '/agent/ops/ScheduledTask.php';

use Pjas\Agentd\OpException;
use Pjas\Agentd\Ops\ScheduledTask;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Spec;

$root = sys_get_temp_dir() . '/pjas-cron-' . bin2hex(random_bytes(4));
$cfg = [
    'web_user' => posix_getpwuid(posix_geteuid())['name'],
    'web_group' => posix_getgrgid(posix_getegid())['name'],
    'cron_dir' => $root . '/cron.d',
    'cron_script_dir' => $root . '/scripts',
    'cron_log_dir' => $root . '/logs',
];
foreach ([$cfg['cron_dir'], $cfg['cron_script_dir'], $cfg['cron_log_dir']] as $dir) {
    mkdir($dir, 0755, true);
}

$op = new ScheduledTask($cfg, new Paths($root . '/www'));
$command = "php artisan schedule:run | tee /tmp/example\nprintf 'done\\n'";
$op->apply(['task' => [
    'id' => 7, 'service' => 'billing', 'schedule' => '*/5 * * * *',
    'command' => $command, 'enabled' => 1,
]]);

$cron = (string) file_get_contents($cfg['cron_dir'] . '/pjas-task-7');
$script = (string) file_get_contents($cfg['cron_script_dir'] . '/task-7.sh');
$fail = 0;

function check(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

check('cronファイルに利用者コマンドを直接書かない', !str_contains($cron, 'artisan'));
check('cronは固定ユーザーと固定スクリプトを実行する', str_contains($cron, $cfg['web_user']) && str_contains($cron, 'task-7.sh'));
check('自由なコマンドはwww-data用スクリプトへ保存する', str_contains($script, $command));
check('実行場所は公開中バージョンに固定する', str_contains($script, '/www/billing/current'));

try {
    Spec::cronSchedule("* * * * *\nroot evil");
    check('cron行の追加を拒否する', false);
} catch (OpException) {
    check('cron行の追加を拒否する', true);
}

$op->delete(['id' => 7]);
check('削除時にcronとスクリプトを消す', !file_exists($cfg['cron_dir'] . '/pjas-task-7')
    && !file_exists($cfg['cron_script_dir'] . '/task-7.sh'));

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $item = $path . '/' . $entry;
        is_dir($item) ? removeTree($item) : unlink($item);
    }
    rmdir($path);
}
removeTree($root);

exit($fail === 0 ? 0 : 1);
