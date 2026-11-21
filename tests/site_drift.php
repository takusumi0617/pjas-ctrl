<?php
declare(strict_types=1);

require __DIR__.'/support/features.php';

use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Paths;
use Pjas\Agentd\SiteDrift;
use Pjas\Agentd\Spec;

[$dir,$cfg] = fixture('site-drift');
try {
    $spec = Spec::service(['name'=>'docs','domain'=>'docs.example.com','runtime'=>'static']);
    $vhost = new Vhost($cfg,new Paths($cfg['www_root']));
    check(SiteDrift::inspect($cfg,$spec)===[],'未公開サイトのApache設定は照合対象外');
    $path = $vhost->confPath('docs');
    $link = $cfg['apache_sites_enabled'].'/'.basename($path);
    $body = $vhost->render($spec);
    file_put_contents($path,$body);
    symlink($path,$link);
    file_put_contents($cfg['daemon_unit_dir'].'/administrator.conf','manual override');
    check(array_column(SiteDrift::inspect($cfg,$spec),'status')===['match','match'],'Apache設定と有効化リンクを確認する');
    file_put_contents($path,$body."\n# changed\n");
    unlink($link);
    symlink($cfg['apache_sites'].'/other.conf',$link);
    check(array_column(SiteDrift::inspect($cfg,$spec),'status')===['changed','changed'],'Apache設定とリンクのずれを区別する');
    unlink($link);
    symlink($path,$link);
    check(array_column(SiteDrift::inspect($cfg,$spec),'status')===['changed','match'],'手動管理の設定は照合しない');
    unlink($link);
    check(array_column(SiteDrift::inspect($cfg,$spec),'status')===['changed','missing'],'有効化リンクの欠落を検出する');

    $params=['service'=>$spec,'require_change_review'=>true];
    $review=$vhost->preview($params);
    check($review['impact']['path']===$path && $review['impact']['changed'] && $review['impact']['risk']==='moderate','変更対象と生成差分をプレビューに含める');
    symlink($path,$link);
    rejects(fn()=>$vhost->apply($params+['change_review'=>$review['change_review']]));
    check(is_link($link) && file_get_contents($path)===$body."\n# changed\n",'確認後に有効化リンクが変わったら適用前に停止する');
    unlink($link);

    $cfg['a2ensite']=$dir.'/enable-site';
    $cfg['a2dissite']=$dir.'/disable-site';
    $cfg['apachectl']=$dir.'/apache';
    file_put_contents($cfg['a2ensite'],"#!/bin/sh\nln -s '$path' '$link'\n");
    file_put_contents($cfg['a2dissite'],"#!/bin/sh\nrm '$link'\n");
    file_put_contents($cfg['apachectl'],"#!/bin/sh\nif [ -f '$dir/fail-once' ]; then rm '$dir/fail-once'; exit 1; fi\nexit 0\n");
    foreach (['a2ensite','a2dissite','apachectl'] as $key) chmod($cfg[$key],0700);
    chmod($path,0640);
    touch($dir.'/fail-once');
    $vhost=new Vhost($cfg,new Paths($cfg['www_root']));
    rejects(fn()=>$vhost->apply(['service'=>$spec]));
    check(!is_link($link) && file_get_contents($path)===$body."\n# changed\n" && (fileperms($path)&0777)===0640,
        'Apache検証失敗後にファイル内容・権限・有効化状態を復旧する');
    $recovery = new \Pjas\Agentd\ApacheRecovery(new \Pjas\Agentd\ControlStore($cfg));
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('fork failed');
    if ($pid === 0) {
        file_put_contents($cfg['apachectl'], "#!/bin/sh\nkill -KILL ".getmypid()."\n");
        $vhost->apply(['service'=>$spec]);
        exit(1);
    }
    pcntl_waitpid($pid,$status);
    check(pcntl_wifsignaled($status) && $recovery->status()['phase']==='pending' && is_link($link),
        'Apache設定の書き換え後に強制終了しても復旧記録を保持する');
    file_put_contents($cfg['apachectl'], "#!/bin/sh\nexit 0\n");
    rejects(fn()=>$vhost->apply(['service'=>$spec]));
    $recovery->rollback(fn()=>$vhost->reload());
    check(!is_link($link) && file_get_contents($path)===$body."\n# changed\n" && $recovery->status()['phase']==='idle',
        'agentなしで中断した設定変更を復旧し、検査後に記録を解除する');
    symlink($path,$link);
    file_put_contents($cfg['a2ensite'], "#!/bin/sh\nexit 0\n");
    $store = new \Pjas\Agentd\ControlStore($cfg);
    $apache = new \Pjas\Agentd\ApacheChange($cfg, static fn()=>['spec'=>$spec,'pending_jobs'=>[]]);
    $steps = [];
    $site = static function(string $operation) use (&$steps): array {
        $steps[] = $operation;
        return ['other-site'];
    };
    $transaction = new \Pjas\Agentd\ChangeTransaction($store, ['apache'=>$apache, 'site'=>$site]);
    $before = file_get_contents($path);
    $plan = $apache->plan('docs');
    $change = $transaction->plan([['type'=>'site','id'=>'fixture'],['type'=>'apache','id'=>$plan['id']]]);
    $transaction->run($change['id'],false);
    check(file_get_contents($path)===$body && $recovery->status()['owner']===$plan['id'], '移行と独立した公開設定を一つの変更として適用し、復旧情報を保持する');
    $transaction->run($change['id'],true);
    check(file_get_contents($path)===$before && end($steps)==='rollback', '一括復旧でApacheと移行の両方を戻す');
    $plan = $apache->plan('docs');
    $change = $transaction->plan([['type'=>'apache','id'=>$plan['id']]]);
    $transaction->run($change['id'],false);
    file_put_contents($path,$before);
    rejects(fn()=>$transaction->commit($change['id']));
    file_put_contents($path,$body);
    $transaction->commit($change['id']);
    check($recovery->status()['phase']==='idle' && $transaction->status($change['id'])['phase']==='committed', '適用状態を確認してApache設定を確定する');
    rejects(fn()=>$transaction->run($change['id'],true));
} finally {
    cleanup($dir);
}
