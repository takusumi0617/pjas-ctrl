<?php
declare(strict_types=1);
foreach (['OpException', 'Spec', 'Proc', 'DaemonDefinition', 'Paths', 'JobLog'] as $file) require dirname(__DIR__) . '/agent/lib/' . $file . '.php';
require dirname(__DIR__) . '/agent/ops/Daemon.php';
require dirname(__DIR__) . '/agent/ops/Deploy.php';
use Pjas\Agentd\DaemonDefinition;
use Pjas\Agentd\OpException;
use Pjas\Agentd\Ops\Daemon;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "ok {$message}\n"; }
function rejects(callable $fn): void { try { $fn(); } catch (OpException) { return; } throw new RuntimeException('拒否されませんでした'); }
$dir = sys_get_temp_dir() . '/pjas-daemon-migration-' . bin2hex(random_bytes(6));
mkdir($dir); mkdir($dir . '/units'); mkdir($dir . '/site-a/websocket', 0755, true);
$definition = "[Service]\nUser=www-data\nWorkingDirectory={$dir}/site-a\nEnvironmentFile=/etc/pjas-chat.env\nEnvironmentFile=-{$dir}/site-a/.env\nExecStartPre=/usr/bin/test -f {$dir}/site-a/websocket/server.php\nExecStart=/usr/bin/php {$dir}/site-a/websocket/server.php start\nRestart=on-failure\n";
file_put_contents($dir . '/original', $definition);
$fake = $dir . '/systemctl';
$script = <<<'SH'
#!/bin/sh
TASK_ROOT='@ROOT@'
for unit do :; done
printf '%s %s\n' "$1" "$unit" >> "$TASK_ROOT/calls"
case "$1" in
cat) cat "$TASK_ROOT/original"; if [ -f "$TASK_ROOT/units/$unit.d/zzzz-pjas-ctrl.conf" ]; then cat "$TASK_ROOT/units/$unit.d/zzzz-pjas-ctrl.conf"; fi;;
show) if [ "$unit" = sleeper.service ]; then state=inactive; else state=active; fi
  printf 'LoadState=loaded\nActiveState=%s\n' "$state";;
list-unit-files) printf 'worker.service enabled\nsleeper.service disabled\n';;
daemon-reload|try-restart) if [ -f "$TASK_ROOT/fail" ]; then exit 1; fi
  if [ "$1" = try-restart ]; then readlink -f "$TASK_ROOT/site-a/current" >> "$TASK_ROOT/restarted-paths"; fi;;
*) exit 0;;
esac
SH;
file_put_contents($fake, str_replace('@ROOT@', $dir, $script)); chmod($fake, 0700);
$cfg = ['systemctl' => $fake, 'www_root' => $dir, 'daemon_dir' => $dir . '/data', 'daemon_unit_dir' => $dir . '/units', 'web_user' => 'www-data', 'web_group' => 'www-data'];
try {
    $d = new Daemon($cfg);
    $d->restartForSite('site-a', static fn($message) => null);
    check(!is_dir($dir . '/data'), '未登録のサイトの公開では台帳作成も再起動も行わない');
    $plan = $d->migrationPlan('site-a', ['worker.service', 'sleeper.service']);
    check(count($plan) === 2 && str_contains($plan[0]['exec_start'], '{{site_root}}/websocket'), '移行前にサイトの起動パスを取り込む');
    check(DaemonDefinition::template('/var/www/a/releases/20261028-000001/run /var/www/a/shared/db /var/www/a-other', '/var/www/a') === '{{site_root}}/run /var/www/a/shared/db /var/www/a-other', '日時付きリリースを正規化し shared と他サイトは変更しない');
    check(!DaemonDefinition::parse(str_replace('User=www-data', 'User=root', $definition), 'www-data')['editable'], 'root実行サービスは内容編集対象にしない');
    rejects(fn() => DaemonDefinition::command("/usr/bin/php x\nUser=root"));
    rejects(fn() => DaemonDefinition::command('+/usr/bin/php x'));
    // ファイルの取り込みと current の切替を再現する。
    mkdir($dir . '/site-a/releases/20261028-000001', 0755, true);
    rename($dir . '/site-a/websocket', $dir . '/site-a/releases/20261028-000001/websocket');
    symlink($dir . '/site-a/releases/20261028-000001', $dir . '/site-a/current');
    $d->migrateForSite('site-a', $plan, static fn($message) => null);
    $override = file_get_contents($dir . '/units/worker.service.d/zzzz-pjas-ctrl.conf');
    check(str_contains($override, 'WorkingDirectory="' . $dir . '/site-a/current"') && str_contains($override, '/site-a/current/websocket/server.php'), '既存デーモンの作業・起動パスをcurrentへ変更');
    check(str_contains($override, 'EnvironmentFile=-' . $dir . '/site-a/current/.env') && str_contains($override, 'ExecStartPre=/usr/bin/test -f ' . $dir . '/site-a/current/websocket/server.php'), 'サイト内の環境変数ファイル参照と起動前コマンドも追従');
    check(file_get_contents($dir . '/original') === $definition, '環境変数ファイルを含む元サービス定義を維持');
    $calls = file_get_contents($dir . '/calls');
    check(str_contains($calls, 'try-restart worker.service') && !str_contains($calls, 'try-restart sleeper.service'), '移行後に稼働中だけを再起動し停止中は保持');
    $d->save(['service' => 'site-a', 'original_unit' => 'worker.service', 'label' => '変更', 'edit_execution' => 1,
        'exec_start' => '/usr/bin/php8.4 {{site_root}}/websocket/server.php start', 'working_subdir' => '']);
    check(str_contains(file_get_contents($dir . '/units/worker.service.d/zzzz-pjas-ctrl.conf'), 'ExecStart=/usr/bin/php8.4 ' . $dir . '/site-a/current/'), '既存デーモンのコマンドを画面入力で編集');
    rejects(fn() => $d->save(['service' => 'site-a', 'original_unit' => 'worker.service', 'label' => '範囲外', 'edit_execution' => 1, 'exec_start' => '/usr/bin/php x', 'working_subdir' => '../other']));
    $before = file_get_contents($dir . '/units/worker.service.d/zzzz-pjas-ctrl.conf');
    touch($dir . '/fail');
    rejects(fn() => $d->save(['service' => 'site-a', 'original_unit' => 'worker.service', 'label' => '失敗', 'edit_execution' => 1, 'exec_start' => '/usr/bin/php changed', 'working_subdir' => '']));
    check(file_get_contents($dir . '/units/worker.service.d/zzzz-pjas-ctrl.conf') === $before, '保存失敗時は上書き設定を復元');
    rejects(fn() => $d->restartForSite('site-a', static fn($message) => null));
    unlink($dir . '/fail');
    file_put_contents($dir . '/calls', '');
    $d->restartForSite('site-b', static fn($message) => null);
    check(!str_contains(file_get_contents($dir . '/calls'), 'try-restart'), '別サイトの公開では再起動しない');
    // 本番の公開切替とリロード処理を使い、公開・切り戻し双方の再起動先を確認する。
    $deploy = (new ReflectionClass(\Pjas\Agentd\Ops\Deploy::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($deploy, 'cfg'))->setValue($deploy, $cfg + ['apachectl' => $fake]);
    (new ReflectionProperty($deploy, 'paths'))->setValue($deploy, new \Pjas\Agentd\Paths($dir));
    $switch = new ReflectionMethod($deploy, 'switchCurrent');
    $reload = new ReflectionMethod($deploy, 'reloadRuntime');
    $log = new \Pjas\Agentd\JobLog(1, $dir . '/jobs');
    mkdir($dir . '/site-a/releases/20261028-000002/websocket', 0755, true);
    file_put_contents($dir . '/restarted-paths', '');
    $switch->invoke($deploy, 'site-a', '20261028-000002', $log);
    $reload->invoke($deploy, ['name' => 'site-a', 'runtime' => 'static'], $log);
    $switch->invoke($deploy, 'site-a', '20261028-000001', $log);
    $reload->invoke($deploy, ['name' => 'site-a', 'runtime' => 'static'], $log);
    $restarted = file($dir . '/restarted-paths', FILE_IGNORE_NEW_LINES);
    check($restarted === [$dir . '/site-a/releases/20261028-000002', $dir . '/site-a/releases/20261028-000001'], '本番の公開・ロールバック経路でデーモンが同じ公開先へ切り替わる');

} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
