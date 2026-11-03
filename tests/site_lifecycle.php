<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\{Paths,Spec,ControlStore,SitePause,SiteLifecycleGuard};use Pjas\Agentd\Ops\{Service,SiteLifecycle};
[$dir,$cfg]=fixture('lifecycle');$paths=new Paths($cfg['www_root']);$spec=Spec::service(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'static','shared'=>['@databases']]);
try{
 (new Service($cfg,$paths))->create(['service'=>$spec]);$rel=$paths->release('demo','20261102-000001');mkdir($rel,0755,true);file_put_contents($rel.'/index.html','code');symlink($rel,$paths->current('demo'));file_put_contents($paths->shared('demo').'/data.txt','data');
 $conf=$cfg['apache_sites'].'/pjas-demo.conf';$body="<VirtualHost *:80>\nServerName demo.example.com\n</VirtualHost>\n";file_put_contents($conf,$body);symlink($conf,$cfg['apache_sites_enabled'].'/pjas-demo.conf');
 $unit='pjas-daemon-example.service';$row=['unit'=>$unit,'service'=>'demo','label'=>'worker','managed'=>true];file_put_contents($cfg['daemon_dir'].'/registry.json',json_encode([$unit=>$row]));file_put_contents($cfg['daemon_unit_dir'].'/'.$unit,'unit');file_put_contents($cfg['daemon_dir'].'/'.$unit.'.sh','script');
 $life=new SiteLifecycle($cfg,$paths);$plan=$life->plan(['service'=>$spec]);check(count($plan['daemons'])===1&&!$plan['blockers'],'削除前に関連する常駐プログラムと影響を一覧にする');
 rejects(fn()=>$life->delete(['service'=>$spec,'mode'=>'detach','fingerprint'=>'old']));
 $r=$life->delete(['service'=>$spec,'mode'=>'detach','fingerprint'=>$plan['fingerprint']]);check($r['state']==='detached'&&file_get_contents($conf)===$body&&file_get_contents($paths->current('demo').'/index.html')==='code','管理解除では公開設定・コード・共有データを変更しない');rejects(fn()=>SiteLifecycleGuard::active($cfg,'demo'));check(json_decode(file_get_contents($cfg['daemon_dir'].'/registry.json'),true)===[],'管理解除後は常駐プログラムの関連付けを解除する');
 $life->restore(['service'=>$spec]);SiteLifecycleGuard::active($cfg,'demo');check(isset(json_decode(file_get_contents($cfg['daemon_dir'].'/registry.json'),true)[$unit]),'管理を再開すると関連付けも復元する');
 $plan=$life->plan(['service'=>$spec]);rejects(fn()=>$life->delete(['service'=>$spec,'mode'=>'delete','writers_stopped'=>true,'fingerprint'=>$plan['fingerprint']]));
 (new SitePause($cfg))->write('demo',['enabled'=>true,'code'=>503,'html'=>'paused']);
 $r=$life->delete(['service'=>$spec,'mode'=>'delete','writers_stopped'=>true,'fingerprint'=>$plan['fingerprint']]);
 check($r['state']==='deleted'&&$r['expires']>time()+29*86400&&!file_exists($paths->base('demo'))&&!file_exists($conf),'完全削除は30日の復元期限を設定し公開設定とサイトを退避する');
 check(!empty($r['backup'])&&!is_file($cfg['daemon_unit_dir'].'/'.$unit)&&(fileperms($cfg['www_root'].'/.pjas-retired')&0777)===0700,'削除前にバックアップを作り、データはroot専用領域へ退避する');
 mkdir($paths->base('demo'));rejects(fn()=>$life->restore(['service'=>$spec]));rmdir($paths->base('demo'));
 file_put_contents($cfg['apachectl'],"#!/bin/sh\nif [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");rejects(fn()=>$life->restore(['service'=>$spec]));check(!is_dir($paths->base('demo'))&&is_dir($cfg['www_root'].'/.pjas-retired/demo/site'),'復元時の設定検査が失敗しても退避データを失わない');file_put_contents($cfg['apachectl'],"#!/bin/sh\nexit 0\n");
 $body=\Pjas\Shared\SensitiveFiles::decorate($body);
 $life->restore(['service'=>$spec]);check(file_get_contents($paths->current('demo').'/index.html')==='code'&&file_get_contents($paths->shared('demo').'/data.txt')==='data'&&file_get_contents($conf)===$body,'同名の保存先を上書きせず、コード・共有データ・設定を復元する');
 $busy=fopen($cfg['apache_sites'].'/.pjas-deploy-demo.lock','c');flock($busy,LOCK_EX);rejects(fn()=>$life->delete(['service'=>$spec,'mode'=>'detach','fingerprint'=>$life->plan(['service'=>$spec])['fingerprint']]));flock($busy,LOCK_UN);fclose($busy);check($life->status(['service'=>$spec])===[],'公開作業のロック中は削除を開始しない');
 file_put_contents($cfg['apache_sites_enabled'].'/other.conf',"DocumentRoot {$cfg['www_root']}/demo-other/current/public\n");check($life->plan(['service'=>$spec])['blockers']===[],'名前の先頭が同じ別サイトを参照先と取り違えない');file_put_contents($cfg['apache_sites_enabled'].'/other.conf',"DocumentRoot \"{$cfg['www_root']}/demo/current/public\"\n");check(count($life->plan(['service'=>$spec])['blockers'])===1,'実際に対象サイトを使う別の公開設定は検出する');unlink($cfg['apache_sites_enabled'].'/other.conf');
 $plan=$life->plan(['service'=>$spec]);file_put_contents($cfg['apachectl'],"#!/bin/sh\nif [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");rejects(fn()=>$life->delete(['service'=>$spec,'mode'=>'delete','writers_stopped'=>true,'fingerprint'=>$plan['fingerprint']]));check(file_get_contents($conf)===$body&&is_dir($paths->base('demo')),'設定検査が失敗したら元の設定とデータを保持する');
 file_put_contents($cfg['apachectl'],"#!/bin/sh\nexit 0\n");$life->restore(['service'=>$spec]);
 $plan=$life->plan(['service'=>$spec]);$life->delete(['service'=>$spec,'mode'=>'delete','writers_stopped'=>true,'fingerprint'=>$plan['fingerprint']]);$store=new ControlStore($cfg);$m=$store->read('retired-demo');$m['expires']=time()-1;$store->write('retired-demo',$m);rejects(fn()=>$life->restore(['service'=>$spec]));$life->cleanup();check($life->status(['service'=>$spec])['state']==='expired'&&!is_dir($cfg['www_root'].'/.pjas-retired/demo'),'30日経過した退避データを消去し、期限終了の記録を残す');
 rejects(fn()=>$life->plan(['service'=>['name'=>'pjas-ctrl']]));check(true,'管理画面自身の削除を拒否する');
}finally{cleanup($dir);}
