<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{Spec,Paths};use Pjas\Agentd\Ops\Vhost;
[$dir,$cfg]=fixture('settings-vhost');
try {
 $spec=Spec::service(['name'=>'alpha','domain'=>'alpha.example.test','runtime'=>'php8.5-fpm','docroot'=>'public','tls'=>false]);
 $cfg['isolation_site']='alpha';$cfg['isolation_runtime']='php8.5-fpm';$cfg['apache_user']=$cfg['web_user'];
 $v=new Vhost($cfg,new Paths($cfg['www_root']));$path=$cfg['apache_sites'].'/pjas-alpha.conf';file_put_contents($path,$v->render($spec));
 $changed=array_replace($spec,['docroot'=>'dist']);$preview=$v->preview(['service'=>$changed,'update_mode'=>'settings']);
 check(str_contains($preview['rendered'],'/current/dist')&&$preview['current_hash']===hash_file('sha256',$path),'保存した専用環境の設定を実ファイルとの差分としてプレビューする');
 $before=file_get_contents($path);rejects(fn()=>$v->apply(['service'=>$changed,'update_mode'=>'settings','current_hash'=>'stale']));check(file_get_contents($path)===$before,'古い確認画面からの上書きを拒否する');
 $v->apply(['service'=>$changed,'update_mode'=>'settings','current_hash'=>$preview['current_hash']]);check(str_contains(file_get_contents($path),'/current/dist'),'構文検査を経て保存した公開フォルダを実設定へ反映する');
 rejects(fn()=>$v->preview(['service'=>$changed,'update_mode'=>'settings','migration_source'=>'other.conf']));
 unset($cfg['isolation_site']);rejects(fn()=>(new Vhost($cfg,new Paths($cfg['www_root'])))->preview(['service'=>$changed,'update_mode'=>'settings']));
 check(true,'継承元の差し込みと未隔離サイトでの全設定更新を拒否する');
}finally{cleanup($dir);}
