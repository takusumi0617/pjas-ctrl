<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{ControlUpdate,ControlStore,GitHubApp,GitRepositories,Proc};
function makeUpdateFixture(bool $httpFailure=false): array {
 [$dir,$cfg]=fixture('control-update');$root=$dir.'/source';mkdir($root,0755);foreach(['app','agent','bin','public','shared','views','db'] as $sub)mkdir($root.'/'.$sub,0755);
 $db=$dir.'/control.sqlite';$sqlite=new SQLite3($db);$sqlite->exec('CREATE TABLE jobs(id INTEGER PRIMARY KEY,state TEXT); CREATE TABLE content(value TEXT); INSERT INTO content VALUES (\'original\')');$sqlite->close();chmod($db,0600);
 file_put_contents($root.'/.gitignore',"config.php\n");file_put_contents($root.'/config.php','<?php return '.var_export(['db'=>$db],true).';');chmod($root.'/config.php',0640);
 file_put_contents($root.'/agent/config.php','<?php return [];');file_put_contents($root.'/app/bootstrap.php',"<?php // PJAS_CONTROL_UPDATE\n");file_put_contents($root.'/views/page.php','<?php echo "old";');file_put_contents($root.'/bin/pjas-migrate','<?php // fixture migration');
 $git=static function(array $a)use($root):string{$r=Proc::run(array_merge(['/usr/bin/git','-c','user.email=fixture@example.test','-c','user.name=Fixture'],$a),$root);if($r['code']!==0)throw new RuntimeException($r['err']);return trim($r['out']);};
 $git(['init','-b','main']);$git(['add','.']);$git(['commit','-m','base']);$base=$git(['rev-parse','HEAD']);
 $git(['clone','--bare',$root,$dir.'/remote.git']);file_put_contents($root.'/views/page.php','<?php echo "new";');mkdir($root.'/app/new');file_put_contents($root.'/app/new/feature.php','<?php // new code');$git(['add','.']);$git(['commit','-m','candidate']);$target=$git(['rev-parse','HEAD']);$git(['push',$dir.'/remote.git','main']);$git(['reset','--hard',$base]);$git(['remote','add','origin','https://github.com/example/private.git']);
 file_put_contents($root.'/agent/config.php','<?php return ["local"=>"preserved"];');chmod($root.'/agent/config.php',0640);
 if(is_executable('/usr/bin/setfacl')){$acl=Proc::run(['/usr/bin/setfacl','-m','u:'.posix_geteuid().':r--',$root.'/agent/config.php']);if($acl['code']===0)file_put_contents($dir.'/acl-supported','1');}
 $cfg['control_update_config']=$dir.'/update.json';$cfg['control_update_runtime']=$dir.'/runtime';file_put_contents($cfg['control_update_config'],json_encode(['version'=>1,'root'=>$root,'repository'=>'https://github.com/example/private.git','branch'=>'main','auth'=>'auto']));chmod($cfg['control_update_config'],0600);
 $store=new ControlStore($cfg);$store->directory('updater/bin');file_put_contents($store->root().'/updater/bin/pjas-control-update','fixture');
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$pem);
 $app=new GitHubApp($cfg,static fn($method,$path,$bearer,$body)=>$method==='GET'?['id'=>123]:['token'=>'private-fixture-token-do-not-leak']);$app->save(['app_id'=>'123','slug'=>'fixture','private_key'=>$pem]);
 $calls=[];$probe=0;
 $runner=static function($args,$cwd,$env,$timeout,$user,$output)use(&$calls,&$probe,$httpFailure,$dir,$cfg,$db):array{
  $calls[]=$args;
  if($args[0]===$cfg['systemctl']&&in_array('--property=Type',$args,true))return ['code'=>0,'out'=>'simple','err'=>''];
  if($args[0]===$cfg['systemctl']&&in_array('show',$args,true)&&str_starts_with(end($args),'php'))return ['code'=>0,'out'=>'RequiredBy=worker.service','err'=>''];
  if($args[0]===$cfg['systemctl']){file_put_contents($dir.'/services.log',implode(' ',$args)."\n",FILE_APPEND);return ['code'=>0,'out'=>in_array('is-active',$args,true)?'active':'','err'=>''];}
  if($args[0]==='/usr/bin/curl'){
   check(in_array('--retry-connrefused',$args,true),'更新・復旧のHTTP検査は起動直後の接続拒否を再試行する');
   foreach (['--retry'=>'5','--retry-delay'=>'1','--retry-max-time'=>'10','--max-time'=>'10'] as $option=>$value) {
    $index=array_search($option,$args,true);
    check($index!==false&&($args[$index+1]??null)===$value,'HTTP検査の再試行を制限する: '.$option);
   }
   ++$probe;return ['code'=>0,'out'=>$httpFailure&&$probe===1?'500':'200','err'=>''];
  }
  if(preg_match('~/php8\.[0-9]+$~',$args[0])) {
   if(in_array('-l',$args,true)){return Proc::run(array_merge([PHP_BINARY,'-c',php_ini_loaded_file()],array_slice($args,1)),$cwd,30);}
   check($user===posix_getpwuid(posix_geteuid())['name'],'管理DBのスキーマ更新はDB所有者へ権限を落とす');
   $sqlite=new SQLite3($db);$sqlite->exec("UPDATE content SET value='migrated'");$sqlite->close();return ['code'=>0,'out'=>'','err'=>''];
  }
  if($args[0]==='/usr/bin/git'&&in_array('fetch',$args,true)){
   foreach($args as &$arg)if($arg==='https://github.com/example/private.git')$arg=$dir.'/remote.git';unset($arg);array_splice($args,1,0,['-c','protocol.file.allow=always']);
  }
  return Proc::run($args,$cwd,$timeout,null,$env,null,$output);
 };
 $authCalls=0;$credentials=static function($repo,$work)use($app,&$authCalls){++$authCalls;return $app->withCredentials($repo,static function($env)use($work){check(is_file($env['PJAS_GITHUB_TOKEN_FILE']),'private取得は一時認証ファイルを使う');return $work($env);});};
 $update=new ControlUpdate($cfg,$runner,$credentials,posix_geteuid());
 return [$dir,$cfg,$root,$base,$target,$update,$git,$store];
}
foreach([false,true] as $fail){
 [$dir,$cfg,$root,$base,$target,$update,$git,$store]=makeUpdateFixture($fail);
 try{
  $review=$update->review();check($review['base']===$base&&$review['target']===$target&&$review['auth']==='github_app'&&!$review['blockers'],'GitHub App認証で固定コミットと変更一覧を確認する');
  check(!str_contains(json_encode($review),'private-fixture-token'),'公開用の確認結果に認証情報を含めない');check(!(glob($store->root().'/requests/*/token')?:[]),'取得後に認証情報を削除する');
  rejects(fn()=>$update->start(['review'=>$review['id'],'confirm'=>false]));rejects(fn()=>$update->start(['review'=>'../invalid','confirm'=>true]));
  $saved=$store->read('control-update-review-'.$review['id']);$expired=$saved;$expired['expires_at']=time()-1;$store->write('control-update-review-'.$review['id'],$expired);rejects(fn()=>$update->start(['review'=>$review['id'],'confirm'=>true]));$store->write('control-update-review-'.$review['id'],$saved);
  $beforeConfig=file_get_contents($root.'/config.php');file_put_contents($root.'/config.php',$beforeConfig.' // changed');rejects(fn()=>$update->start(['review'=>$review['id'],'confirm'=>true]));file_put_contents($root.'/config.php',$beforeConfig);
  check(true,'期限切れの確認・確認後の設定変更を拒否する');
  $prepared = [];
  if ($fail) {
   $migrationId=str_repeat('a',24);
   $store->write('upgrade-active-example',['id'=>$migrationId]);
   $store->write('site-upgrade-'.$migrationId,['name'=>'example','phase'=>'quiescing']);
   $prepared=['example'=>$migrationId];
   rejects(fn()=>$update->start(['review'=>$review['id'],'confirm'=>true],true,$prepared));
   $store->write('site-upgrade-'.$migrationId,['name'=>'example','phase'=>'prepared']);
   rejects(fn()=>$update->start(['review'=>$review['id'],'confirm'=>true],true));
   $sqlite=new SQLite3($dir.'/control.sqlite');
   $sqlite->exec("UPDATE content SET value='site-prepared'");
   $sqlite->close();
  }
  $job=$update->start(['review'=>$review['id'],'confirm'=>true],$fail,$prepared);
  if($fail)check($job['id']===$review['id']&&$update->start(['review'=>$review['id'],'confirm'=>true],true)===$job,'一括変更からの更新起動を再実行しても同じジョブになる');
  rejects(fn()=>$update->start(['review'=>$review['id'],'confirm'=>true]));
  $oldMask=umask(0077);try{$result=$update->execute($job['id']);}finally{umask($oldMask);}
  $sqlite=new SQLite3($dir.'/control.sqlite');$value=$sqlite->querySingle('SELECT value FROM content');$sqlite->close();
  if(!$fail)check((fileperms($root.'/app/new/feature.php')&0777)===0640&&(fileperms($root.'/app/new')&0777)===0750,'厳しいumaskを維持し、新しいコードだけを管理ユーザーのグループに読み取り可能にする');
  if($fail)check(str_contains($store->read('control-update-error-'.$job['id'])['reason']??'','HTTP 500'),'最終HTTPステータスを更新エラーに記録する');
  if($fail)check($result['state']['phase']==='failed'&&$result['state']['rollback_complete']&&$git(['rev-parse','HEAD'])===$base&&$value==='site-prepared'&&$store->read('upgrade-active-example')===['id'=>$migrationId],'ctrl更新失敗後はサイト移行で準備したDBと移行記録が一致する');
  else check($result['state']['phase']==='succeeded'&&$git(['rev-parse','HEAD'])===$target&&$value==='migrated','実Gitのfast-forwardとSQLite更新を完了する');
  check(str_contains(file_get_contents($root.'/agent/config.php'),'preserved')&&(fileperms($root.'/agent/config.php')&0777)===0640,'ローカルagent設定と権限を維持する');
  check(str_contains(file_get_contents($dir.'/services.log'),'start worker.service'),'PHP停止で連動停止する稼働中サービスも復帰する');
  if(is_file($dir.'/acl-supported'))check(str_contains(Proc::must(['/usr/bin/getfacl','-cpn',$root.'/agent/config.php']),'user:'.posix_geteuid().':r--'),'設定の既存ACLも退避・復旧で維持する');
  check(!is_file($cfg['control_update_runtime'].'/maintenance'),'復旧・検査後にだけ管理画面の停止を解除する');
  if(!$fail){
   $store->write('control-update',['id'=>$job['id'],'phase'=>'running','detail'=>'interrupted']);file_put_contents($cfg['control_update_runtime'].'/maintenance',hash('sha256','fixture'));
   $recovery=$update->execute($job['id'],true);$sqlite=new SQLite3($dir.'/control.sqlite');$restored=$sqlite->querySingle('SELECT value FROM content');$sqlite->close();
   check($recovery['state']['rollback_complete']&&$git(['rev-parse','HEAD'])===$base&&$restored==='original','サービス中断後も保存済み記録からコード・DBを復旧できる');
  }
  $before=$git(['rev-parse','HEAD']);$update->execute($job['id'],true);check($git(['rev-parse','HEAD'])===$before,'成功・復旧済みの更新を再度巻き戻さない');
 }finally{cleanup($dir);}
}
check(ControlUpdate::blockers(['systemd/service'],['100644'])!==[],'サービス定義の変更はサーバーでの照合を要求する');
check(ControlUpdate::blockers(['views/page.php'],['120000'])!==[],'リンク・サブモジュールの更新を拒否する');
check(ControlUpdate::origin('git@github.com:example/private.git')==='https://github.com/example/private.git','SSHのoriginもGitHub App用HTTPSへ正規化する');

check(ControlUpdate::blockers(['agent/lib/Proc.php'],['100644'])!==[],'独立更新基盤が使うライブラリの変更も手動照合へ分ける');
