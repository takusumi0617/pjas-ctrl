<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\SecurityUpdate;
$root=dirname(__DIR__);
$certUnit='pjas-component-pjas-cert-web.service';$certPath='/etc/systemd/system/'.$certUnit.'.d/pjas-cert.conf';
$certOverride="[Unit]\nAfter=systemd-tmpfiles-setup.service\n[Service]\nReadWritePaths=/run/pjas-cert/coordination.lock\nMemoryMax=256M\nCPUQuota=100%\nEnvironment=PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d:/etc/pjas-cert/php-ini\n";
check(SecurityUpdate::reviewedComponentDropIn($certUnit,$certPath,$certOverride),'確認したpjas-cert専用の設定を内容まで照合して維持する');
foreach ([
    $certOverride."ExecStart=/bin/true\n",
    $certOverride."Environment=LD_PRELOAD=/tmp/inject.so\n",
    str_replace('coordination.lock','../other',$certOverride),
    str_replace('MemoryMax=256M','MemoryMax=infinity',$certOverride),
    str_replace('/etc/pjas-cert/php-ini','/tmp/php-ini',$certOverride),
    str_replace('After=systemd-tmpfiles-setup.service','After=other.service',$certOverride),
    str_replace("CPUQuota=100%\n",'',$certOverride),
] as $changed) check(!SecurityUpdate::reviewedComponentDropIn($certUnit,$certPath,$changed),'未確認の設定追加・変更・削除を拒否する');
check(!SecurityUpdate::reviewedComponentDropIn('other.service',$certPath,$certOverride),'他サービスの設定を取り違えない');
check(!SecurityUpdate::reviewedComponentDropIn($certUnit,'/tmp/pjas-cert.conf',$certOverride),'別の場所に置かれた追加設定を許可しない');
foreach (['web','ca','sign'] as $component) {
    $unit='pjas-component-pjas-cert-'.$component.'.service';$dir='/etc/systemd/system/'.$unit.'.d/';
    $base=$component==='web'?$certOverride:
        "[Unit]\nAfter=systemd-tmpfiles-setup.service\n[Service]\nReadWritePaths=/run/pjas-cert/coordination.lock\nMemoryMax=256M\nCPUQuota=100%\nReadWritePaths=/run/pjas-cert/{$component}\n";
    $command=$component==='web'?'/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /run/pjas-components/pjas-cert-web/php-fpm.conf':'/usr/bin/php8.5 bin/worker '.$component;
    $migration="[Service]\nExecStart=\nExecStart={$command}\n";
    if ($component==='web') $migration.="Environment=PHP_INI_SCAN_DIR=/etc/php/8.5/fpm/conf.d:/etc/pjas-cert/php-ini\n";
    check(SecurityUpdate::reviewedComponentDropIn($unit,$dir.'pjas-cert.conf',$base),$component.'の確認済み共有ロック・実行領域・リソース制限を維持する');
    check(SecurityUpdate::reviewedComponentDropIn($unit,$dir.'zzzz-php85-migration.conf',$migration),$component.'の確認済みPHP 8.5移行設定を維持する');
    foreach ([
        str_replace("ExecStart=\nExecStart={$command}","ExecStart={$command}\nExecStart=",$migration),
        str_replace("ExecStart=\n",'',$migration),
        $migration."ExecStart=/bin/true\n",
        str_replace('8.5','8.4',$migration),
        $migration."Environment=LD_PRELOAD=/tmp/inject.so\n",
    ] as $bad) check(!SecurityUpdate::reviewedComponentDropIn($unit,$dir.'zzzz-php85-migration.conf',$bad),$component.'の未確認の実行内容・リセット順序・環境変数を拒否する');
    check(!SecurityUpdate::reviewedComponentDropIn($unit,$dir.'pjas-cert.conf',$base."ReadWritePaths=/\n"),$component.'の書き込み範囲拡大を拒否する');
    $requirements=SecurityUpdate::reviewedRequirements($unit,$dir.'pjas-cert.conf',$base);
    check(($requirements['/run/pjas-cert/coordination.lock']??'')==='file',$component.'の共有ロックを前提条件として確認する');
    if ($component!=='web') check(($requirements['/run/pjas-cert/'.$component]??'')==='directory',$component.'の実行用ディレクトリを前提条件として確認する');
    $requirements=SecurityUpdate::reviewedRequirements($unit,$dir.'zzzz-php85-migration.conf',$migration);
    $binary=$component==='web'?'/usr/sbin/php-fpm8.5':'/usr/bin/php8.5';
    check(($requirements[$binary]??'')==='executable',$component.'のPHP 8.5実行ファイルを前提条件として確認する');
    if ($component==='web') check(($requirements['/etc/php/8.5/fpm/conf.d']??'')==='directory' && ($requirements['/etc/pjas-cert/php-ini']??'')==='directory','PHP-FPM 8.5とpjas-certのINI読み込み先を確認する');
}
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
$resourceUnit='pjas-component-cert-ca.service';
$resourcePath='/etc/systemd/system.control/'.$resourceUnit.'.d/50-MemoryMax.conf';
check(SecurityUpdate::resourceDropIn($resourceUnit,$resourcePath,"# systemctl set-property\n[Service]\nMemoryMax=536870912\n"),'systemctlで設定した既存リソース制限を維持して移行できる');
check(!SecurityUpdate::resourceDropIn($resourceUnit,$resourcePath,"[Service]\nMemoryMax=536870912\nExecStart=/bin/true\n"),'リソース制限と見せかけた実行内容変更を拒否する');
check(!SecurityUpdate::resourceDropIn($resourceUnit,$resourcePath,"[Service]\nMemoryMax=536870912\n[Unit]\nRequires=other.service\n"),'リソース制限内の依存関係変更を拒否する');
check(!SecurityUpdate::resourceDropIn('other.service',$resourcePath,"[Service]\nMemoryMax=536870912\n"),'他のunitの制限を取り違えない');

if (is_executable('/usr/bin/systemd-analyze') && is_executable('/usr/sbin/apache2')) {
    [$verifyDir,$verifyCfg]=fixture('unit-verify');
    try {
        foreach (['pjas-agentd','pjas-control-web'] as $name) {
            copy($root.'/systemd/'.$name.'.service',$verifyDir.'/'.$name.'.service');
            mkdir($verifyDir.'/'.$name.'.service.d',0755);
            copy($root.'/systemd/'.$name.'-security.conf',$verifyDir.'/'.$name.'.service.d/90-pjas-security.conf');
        }
        file_put_contents($verifyDir.'/php8.4-fpm.service',"[Service]\nExecStart=/usr/bin/true\n");
        file_put_contents($verifyDir.'/pjas-components-boot.service',$boot);
        $verified=Pjas\Agentd\Proc::run(['/usr/bin/systemd-analyze','--user','verify',
            $verifyDir.'/pjas-agentd.service',$verifyDir.'/pjas-control-web.service',$verifyDir.'/pjas-components-boot.service'],null,30,null,
            ['PATH'=>'/usr/bin:/bin','XDG_RUNTIME_DIR'=>$verifyDir,'SYSTEMD_UNIT_PATH'=>$verifyDir.':/usr/lib/systemd/system']);
        check($verified['code']===0,'制限設定と起動時復帰unitをsystemdのパーサーで検証する: '.$verified['err']);
    } finally { cleanup($verifyDir); }
}

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
