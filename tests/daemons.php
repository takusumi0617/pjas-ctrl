<?php
declare(strict_types=1);
require dirname(__DIR__) . '/agent/lib/Proc.php';
require dirname(__DIR__) . '/agent/lib/OpException.php';
require dirname(__DIR__) . '/agent/lib/Spec.php';
require dirname(__DIR__) . '/agent/lib/DaemonDefinition.php';
require dirname(__DIR__) . '/agent/ops/Daemon.php';
use Pjas\Agentd\Ops\Daemon;
use Pjas\Agentd\OpException;
$dir = sys_get_temp_dir() . '/pjas-daemons-' . bin2hex(random_bytes(6));
mkdir($dir); mkdir($dir . '/units'); mkdir($dir . '/site-a/current', 0755, true); mkdir($dir . '/site-b/current', 0755, true);
$fake = $dir . '/systemctl';
file_put_contents($fake, <<<'SH'
#!/bin/sh
case "$1" in
show) printf 'LoadState=loaded\nActiveState=active\nSubState=running\nUnitFileState=enabled\n';;
list-unit-files) printf 'worker.service enabled\nunrelated.service disabled\npjas-agentd.service enabled\n';;
start|stop|restart|daemon-reload) exit 0;;
*) exit 1;;
esac
SH);
chmod($fake, 0700);
$cfg = ['systemctl' => $fake, 'www_root' => $dir, 'daemon_dir' => $dir . '/data', 'daemon_unit_dir' => $dir . '/units', 'web_user' => 'www-data', 'web_group' => 'www-data'];
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "ok {$message}\n"; }
function rejects(callable $fn): void { try { $fn(); } catch (OpException) { return; } throw new RuntimeException('操作を拒否しませんでした'); }
try {
    $d = new Daemon($cfg);
    check($d->listing([])['daemons'] === [], '未関連付けのサービスは一覧に出ない');
    rejects(fn() => $d->action(['service' => 'site-a', 'unit' => 'worker.service', 'action' => 'start']));
    $d->save(['service' => 'site-a', 'unit' => 'worker.service', 'mode' => 'existing', 'label' => '既存作業']);
    check($d->listing([])['daemons'][0]['state']['ActiveState'] === 'active', '関連付け済みサービスの状態取得');
    $d->action(['service' => 'site-a', 'unit' => 'worker.service', 'action' => 'start']);
    rejects(fn() => $d->action(['service' => 'site-b', 'unit' => 'worker.service', 'action' => 'stop']));
    rejects(fn() => $d->action(['service' => 'site-a', 'unit' => 'unrelated.service', 'action' => 'stop']));
    rejects(fn() => $d->save(['service' => 'site-b', 'unit' => 'worker.service', 'mode' => 'existing', 'label' => '重複']));
    rejects(fn() => $d->save(['service' => 'site-a', 'unit' => 'pjas-agentd.service', 'mode' => 'existing', 'label' => '自身']));
    rejects(fn() => $d->save(['service' => 'missing', 'mode' => 'new', 'label' => '存在しないサイト', 'command' => 'sleep 60']));
    check(!in_array('worker.service', $d->candidates([])['units'], true), '関連付け済みは候補から除外');
    $row = $d->save(['service' => 'site-a', 'mode' => 'new', 'label' => '新規作業', 'command' => 'exec /usr/bin/php8.5 worker.php']);
    $unit = $row['unit'];
    $body = file_get_contents($dir . '/units/' . $unit);
    check(str_contains($body, 'User=www-data') && str_contains($body, '/site-a/current') && str_contains($body, 'Restart=on-failure'), '新規サービスはサイト内で非特権ユーザー実行');
    check(str_contains($body, 'WorkingDirectory=' . $dir . '/site-a/current' . "\n") && !str_contains($body, 'WorkingDirectory="'), 'WorkingDirectoryのパスに引用符を含めない');
    $d->save(['service' => 'site-a', 'original_unit' => $unit, 'label' => '編集後', 'command' => 'exec sleep 120']);
    check(str_contains(file_get_contents($dir . '/data/' . $unit . '.sh'), 'exec sleep 120'), '登録した実行内容を編集');
    rejects(fn() => $d->save(['service' => 'site-b', 'original_unit' => $unit, 'label' => '他サイト', 'command' => 'exec sleep 1']));
    rejects(fn() => $d->action(['service' => 'site-a', 'unit' => $unit, 'action' => 'enable']));
    $d->save(['service' => 'site-a', 'original_unit' => 'worker.service', 'label' => '表示名だけ変更', 'command' => 'ignored']);
    check(!file_exists($dir . '/units/worker.service'), '既存サービス定義は書き換えない');
    check(count((new Daemon($cfg))->listing([])['daemons']) === 2, '関連付け台帳は再読み込み後も保持');
    $d->save(['service' => 'site-a', 'target_service' => 'site-b', 'original_unit' => 'worker.service', 'label' => '移動']);
    rejects(fn() => $d->action(['service' => 'site-a', 'unit' => 'worker.service', 'action' => 'start']));
    $d->action(['service' => 'site-b', 'unit' => 'worker.service', 'action' => 'start']);
    check(true, '関連付け先を編集すると以前のサイトからの操作を拒否');
    $d->action(['service'=>'site-b','unit'=>'worker.service','action'=>'detach']);
    check(!array_filter($d->listing([])['daemons'],static fn($r)=>$r['unit']==='worker.service'),'関連付け解除は台帳からだけ除外する');
    rejects(fn()=>$d->action(['service'=>'site-b','unit'=>'worker.service','action'=>'start']));
    // daemon-reload が失敗したら、実行内容とサービス定義を元へ戻す。
    file_put_contents($fake, "#!/bin/sh\nexit 1\n");
    rejects(fn() => $d->save(['service' => 'site-a', 'original_unit' => $unit, 'label' => '失敗', 'command' => 'exec sleep 999']));
    check(str_contains(file_get_contents($dir . '/data/' . $unit . '.sh'), 'exec sleep 120'), '設定反映の失敗時は以前の実行内容へ復元');
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
