<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\SecurityUpdate;
$root=dirname(__DIR__);
$files=SecurityUpdate::files($root);
check(count($files)===3,'限定更新は専用drop-in二つと起動時復帰unitだけを配置する');
check(!isset($files['/etc/systemd/system/pjas-agentd.service']),'既存のagent本体unitを上書きしない');
$agent=$files['/etc/systemd/system/pjas-agentd.service.d/90-pjas-security.conf'];
$web=$files['/etc/systemd/system/pjas-control-web.service.d/90-pjas-security.conf'];
foreach ([$agent,$web] as $body) {
    foreach (['PrivateDevices=yes','ProtectKernelTunables=yes','ProtectKernelModules=yes','ProtectControlGroups=yes','RestrictSUIDSGID=yes','AmbientCapabilities='] as $line) check(str_contains($body,$line),'防御設定: '.$line);
    check(!preg_match('/CAP_(SYS_ADMIN|SYS_MODULE|NET_ADMIN|SYS_PTRACE|SYS_BOOT|BPF)\b/',$body),'カーネル・ネットワーク管理権限を許可しない');
    check(!preg_match('/^(ReadWritePaths|MemoryHigh|MemoryMax|TasksMax|ExecStart)=/m',$body),'環境固有パス・リソース制限・実行先を書き換えない');
}
check(str_contains($web,'IPAddressDeny=any') && str_contains($web,'IPAddressAllow=localhost'),'管理HTTPはループバック通信だけを許可する');
check(!str_contains($agent,'IPAddressDeny=any'),'GitHub取得・通知・ヘルスチェックに必要なagentの外向き通信を維持する');
check(str_contains($agent,'UMask=0022'),'公開ファイルの読み込み権限を既存と互換に保つ');
$boot=$files['/etc/systemd/system/pjas-components-boot.service'];
check(str_contains($boot,'After=network-online.target pjas-agentd.service'),'agentの起動後に承認済み状態の復帰を行う');
check(str_contains($boot,$root.'/bin/pjas-components-boot'),'起動時復帰は実際のコード配置先を参照する');

// Behavioral check: only approved generated units are migrated, never a
// catalog-assigned external service; tampered files and symlinks must fail.
if (function_exists('posix_geteuid')) {
    [$dir,$cfg]=fixture('security-update');
    try {
        $store=new Pjas\Agentd\ControlStore($cfg);
        $unit=$dir.'/units/pjas-component-cert-ca.service';file_put_contents($unit,'approved');
        $state=['manifest'=>['components'=>[['id'=>'ca'],['id'=>'external','existing_service'=>'old']]],'files'=>[$unit=>hash('sha256','approved')]];
        $store->write('components-cert',$state);
        check(SecurityUpdate::managedUnits($cfg)===['pjas-component-cert-ca.service'],'既存サービスを除き承認済み生成unitだけを移行する');
        file_put_contents($unit,'modified');rejects(fn()=>SecurityUpdate::managedUnits($cfg));
        unlink($unit);symlink($dir.'/command',$unit);rejects(fn()=>SecurityUpdate::managedUnits($cfg));
        check(true,'生成unitの改変とリンクを検出して適用を中断する');
    } finally { cleanup($dir); }
}
