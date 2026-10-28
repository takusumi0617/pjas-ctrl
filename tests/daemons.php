<?php
declare(strict_types=1);
require dirname(__DIR__) . '/agent/lib/Proc.php';
require dirname(__DIR__) . '/agent/lib/OpException.php';
require dirname(__DIR__) . '/agent/ops/Daemon.php';
use Pjas\Agentd\Ops\Daemon;
use Pjas\Agentd\OpException;
$fake = tempnam(sys_get_temp_dir(), 'pjas-systemctl-');
file_put_contents($fake, <<<'SH'
#!/bin/sh
if [ "$1" = show ]; then
    printf 'LoadState=loaded\nActiveState=active\nSubState=running\nUnitFileState=enabled\n'
elif [ "$1" = start ] && [ "$2" = -- ] && [ "$3" = worker.service ]; then
    exit 0
else
    exit 1
fi
SH);
chmod($fake, 0700);
try {
    $d = new Daemon(['systemctl' => $fake, 'managed_daemons' => ['worker.service' => '作業プログラム']]);
    $rows = $d->listing([])['daemons'];
    if ($rows[0]['state']['ActiveState'] !== 'active' || $rows[0]['label'] !== '作業プログラム') throw new RuntimeException('状態の読み取りに失敗');
    $d->action(['unit' => 'worker.service', 'action' => 'start']);
    foreach ([['unit' => 'other.service', 'action' => 'start'], ['unit' => 'worker.service', 'action' => 'enable'], ['unit' => '--all', 'action' => 'stop']] as $p) {
        try { $d->action($p); } catch (OpException) { continue; }
        throw new RuntimeException('未許可の操作を受け付けました');
    }
    try { $d->action(['unit' => 'worker.service', 'action' => 'restart']); throw new RuntimeException('失敗を無視しました'); } catch (OpException) {}
    echo "ok 状態取得、起動、対象外・不正操作の拒否、コマンド失敗の伝達\n";
} finally { unlink($fake); }
