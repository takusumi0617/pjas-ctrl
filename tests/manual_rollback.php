<?php
declare(strict_types=1);
foreach (['OpException','Spec','Paths','Proc','JobLog','SitePause','InheritedVhost'] as $f) require_once dirname(__DIR__).'/agent/lib/'.$f.'.php';
foreach (['Daemon','Vhost','Health','Deploy'] as $f) require_once dirname(__DIR__).'/agent/ops/'.$f.'.php';
use Pjas\Agentd\Paths;
use Pjas\Agentd\Spec;
use Pjas\Agentd\SitePause;
use Pjas\Agentd\Ops\Deploy;
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Ops\Health;
use Pjas\Agentd\OpException;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "ok {$label}\n";}
$dir=sys_get_temp_dir().'/pjas-rollback-'.bin2hex(random_bytes(6));mkdir($dir);
foreach (['sites','jobs','daemons','www/demo/shared','www/demo/releases/20261028-000001','www/demo/releases/20261028-000002'] as $p)mkdir($dir.'/'.$p,0755,true);
$fake=$dir.'/apache';file_put_contents($fake,"#!/bin/sh\nexit 0\n");chmod($fake,0700);
$user=posix_getpwuid(posix_geteuid())['name'];$group=posix_getgrgid(posix_getegid())['name'];
$cfg=['apache_sites'=>$dir.'/sites','apachectl'=>$fake,'job_dir'=>$dir.'/jobs','daemon_dir'=>$dir.'/daemons','pause_dir'=>$dir.'/pauses','health_retries'=>0,'health_wait'=>0,'web_user'=>$user,'web_group'=>$group];
$paths=new Paths($dir.'/www');$deploy=new Deploy($cfg,$paths,new Vhost($cfg,$paths),new Health($cfg));
$spec=Spec::service(['name'=>'demo','domain'=>'example.com','runtime'=>'static','shared'=>['@databases']]);
$run=new ReflectionMethod($deploy,'runRollback');
$old='20261028-000001';$latest='20261028-000002';
symlink($paths->release('demo',$latest),$paths->current('demo'));
file_put_contents($paths->release('demo',$latest).'/notes.db','LATEST USER DATA');
file_put_contents($paths->release('demo',$old).'/notes.db','OLD SNAPSHOT');
try{
 $available=$deploy->releases(['service'=>['name'=>'demo']]);
 check($available['current']===$latest&&$available['tags']===[$latest,$old],'サーバーに残るバージョンと現在の公開先を取得');
 $pause=new SitePause($cfg);$pause->write('demo',['enabled'=>true,'code'=>503,'html'=>'paused']);
 $run->invoke($deploy,1,$spec,$old,$latest);
 $state=json_decode(file_get_contents($dir.'/jobs/1.json'),true);
 check($paths->currentTag('demo')===$old&&$state['state']==='succeeded'&&$state['release']['tag']===$old,'以前のバージョンへ切り替えてジョブへ記録');
 check(file_get_contents($paths->current('demo').'/notes.db')==='LATEST USER DATA','ロールバック後もDBは最新データを保持');
 check($pause->read('demo')['enabled'],'公開停止中のロールバックでも停止状態を維持');
 $pause->write('demo',['enabled'=>false,'code'=>503,'html'=>'paused']);
 $run->invoke($deploy,2,$spec,$latest,$old);
 $state=json_decode(file_get_contents($dir.'/jobs/2.json'),true);
 check($state['state']==='failed'&&$paths->currentTag('demo')===$old,'動作確認に失敗したら直前の公開先へ復帰して失敗扱い');
 check(file_get_contents($paths->current('demo').'/notes.db')==='LATEST USER DATA','失敗時の復帰でもDBの内容を戻さない');
 $run->invoke($deploy,3,$spec,$latest,$latest);
 check($paths->currentTag('demo')===$old&&json_decode(file_get_contents($dir.'/jobs/3.json'),true)['state']==='failed','画面確認後に公開先が変わった場合は切り替えない');
 symlink($dir,$paths->releases('demo').'/20261028-000003');
 check(!in_array('20261028-000003',$deploy->releases(['service'=>['name'=>'demo']])['tags'],true),'戻す対象にリリース外へのリンクを含めない');
 try{$deploy->rollback(['job_id'=>0,'service'=>$spec,'tag'=>$old]);throw new RuntimeException('job_id検証なし');}catch(OpException){echo "ok 不正なジョブIDを拒否\n";}
 // 再読み込みが失敗しても成功と報告しない。
 file_put_contents($fake,"#!/bin/sh\nexit 1\n");
 $pause->write('demo',['enabled'=>true,'code'=>503,'html'=>'paused']);
 $run->invoke($deploy,4,$spec,$latest,$old);
 check($paths->currentTag('demo')===$old&&json_decode(file_get_contents($dir.'/jobs/4.json'),true)['state']==='failed','再読み込み失敗時にも元の公開先へ復帰');
 file_put_contents($fake,"#!/bin/sh\nexit 0\n");
 $queued=$deploy->rollback(['job_id'=>5,'service'=>$spec,'tag'=>$latest,'expected_current'=>$old]);
 pcntl_waitpid($queued['pid'],$status);
 check(pcntl_wexitstatus($status)===0&&$paths->currentTag('demo')===$latest&&json_decode(file_get_contents($dir.'/jobs/5.json'),true)['state']==='succeeded','実際の非同期ロールバック要求でも子プロセスが公開先を切り替える');
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
