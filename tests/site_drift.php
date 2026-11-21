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
    check($review['impact']['path']===$path && $review['impact']['changed'],'変更対象と生成差分をプレビューに含める');
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
} finally {
    cleanup($dir);
}
