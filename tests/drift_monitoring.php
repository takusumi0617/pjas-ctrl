<?php
declare(strict_types=1);

require __DIR__.'/support/features.php';

use Pjas\Agentd\ControlStore;
use Pjas\Agentd\Ops\Operations;
use Pjas\Agentd\Paths;
use Pjas\Domain\DriftMonitoring;

$path = '/example/site.conf';
$row = static fn(string $status): array => ['path'=>$path,'status'=>$status];
$initial = DriftMonitoring::compare([],[$row('changed')]);
check($initial['added']===[$path] && !$initial['resolved'],'初回の構成異常を通知対象にする');
$same = DriftMonitoring::compare($initial['failures'],[$row('changed')]);
check(!$same['added'] && !$same['resolved'],'同じ異常の通知を抑止する');
$skip = DriftMonitoring::compare($initial['failures'],[$row('skip')]);
check($skip['failures']===$initial['failures'] && !$skip['resolved'],'検査対象外になっても復旧扱いにしない');
$resolved = DriftMonitoring::compare($skip['failures'],[$row('match')]);
check($resolved['resolved']===[$path] && !$resolved['failures'],'一致を確認してから復旧を通知する');
check(!DriftMonitoring::compare($resolved['failures'],[$row('match')])['resolved'],'復旧を重複通知しない');

[$dir,$cfg] = fixture('drift-monitor');
try {
    $ops = new Operations($cfg,new Paths($cfg['www_root']));
    $params = ['site'=>'docs','spec'=>['domain'=>'docs.example.com','runtime'=>'static']];
    check(isset($ops->drift($params)['skip']),'未公開サイトは照合対象なし');
    $file=fopen($cfg['apache_sites'].'/.pjas-deploy-docs.lock','c');
    flock($file,LOCK_EX);
    try {
        check(($ops->drift($params)['skip']??'')==='設定変更または公開作業中','公開ロック取得中に構成を検査しない');
    } finally {
        fclose($file);
    }
    $store=new ControlStore($cfg);
    $store->write('upgrade-active-docs',['id'=>'fixture']);
    check(($ops->drift($params)['skip']??'')==='サイト移行・管理終了の処理中','移行中に構成を検査しない');
    $store->write('upgrade-active-docs',[]);
    $store->write('components-docs',['phase'=>'registered']);
    $store->write('component-tx-docs',['phase'=>'pending']);
    check(($ops->drift($params)['skip']??'')==='コンポーネント設定の復旧待ち','v2設定の復旧待ちに検査しない');
} finally {
    cleanup($dir);
}
