<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{ComponentPolicy,ControlStore,Proc};
use Pjas\Agentd\Ops\Components;
[$dir,$cfg]=fixture('components');
$componentType=getenv('PJAS_COMPONENT_TEST_TYPE')==='php'?'php':'worker';
try {
 $cfg['web_user']='www-data';$cfg['web_group']='www-data';$cfg['preview_user']='pjas-preview';$cfg['component_catalog']=$dir.'/catalog.json';$cfg['component_run_dir']=$dir.'/run';
 $cfg['systemctl']=$dir.'/systemctl';$cfg['systemd_run']=$dir.'/systemd-run';
 $user=posix_getpwuid(posix_geteuid())['name'];$group=posix_getgrgid(posix_getegid())['name'];
 copy('/usr/bin/true',$dir.'/true');chmod($dir.'/true',0700);
 $manifest=['version'=>2,'site'=>['name'=>'cert','domain'=>'cert.example.com','runtime'=>'static','document_root'=>'public','https'=>false],'components'=>[['id'=>'ca','type'=>'worker','execution_profile'=>'ca','command'=>[$dir.'/true'],'storage'=>['state'],'secrets'=>['api'],'setup'=>['init'],'health_checks'=>['ready']]],'backup_policy'=>'backup'];
 mkdir($dir.'/data',0700);mkdir($dir.'/www/cert',0755);mkdir($dir.'/run',0755);file_put_contents($dir.'/data/db','original');chmod($dir.'/data/db',0600);file_put_contents($dir.'/key',random_bytes(32));chmod($dir.'/key',0600);
 $catalog=['version'=>1,'sites'=>['cert'=>['repository'=>'https://github.com/test/cert','domain'=>'cert.example.com','runtime'=>'static','document_root'=>'public','https'=>false]],'profiles'=>['ca'=>['sites'=>['cert'],'type'=>'worker','user'=>$user,'group'=>$group,'executables'=>[$dir.'/true']]],'storage'=>['state'=>['sites'=>['cert'],'profile'=>'ca','path'=>$dir.'/data','purpose'=>'intermediate-ca']],'secrets'=>['api'=>['sites'=>['cert'],'profile'=>'ca','path'=>$dir.'/api-secret','purpose'=>'api']],'setup'=>['init'=>['sites'=>['cert'],'profile'=>'ca','command'=>[$dir.'/true'],'check'=>[$dir.'/true'],'absent'=>[$dir.'/true']]],'health'=>['ready'=>['sites'=>['cert'],'profile'=>'ca','command'=>[$dir.'/true']]],'backup_policies'=>['backup'=>['sites'=>['cert'],'storage'=>['state'],'key_file'=>$dir.'/key','verify_restore'=>[$dir.'/true']]]];
 if ($componentType==='php') {
  $manifest['site']['runtime']='php8.4-fpm';$catalog['sites']['cert']['runtime']='php8.4-fpm';
  $manifest['components'][0]['type']='php';$manifest['components'][0]['command']=[];
  $catalog['profiles']['ca']['type']='php';$catalog['profiles']['ca']['fpm_binary']=$dir.'/true';$catalog['profiles']['ca']['apache_user']=$user;
 }
 file_put_contents($cfg['component_catalog'],json_encode($catalog));chmod($cfg['component_catalog'],0600);
 file_put_contents($cfg['systemctl'],"#!/bin/sh\ncase \"\$1\" in\nis-active) if [ -f '$dir/active' ]; then echo active; exit 0; else echo inactive; exit 3; fi;;\nstart) touch '$dir/active';;\nstop) rm -f '$dir/active';;\nesac\nexit 0\n");chmod($cfg['systemctl'],0700);
 file_put_contents($cfg['systemd_run'],"#!/bin/sh\necho run >> '$dir/tasks'\nif [ -f '$dir/fail-task' ]; then exit 1; fi\nexit 0\n");chmod($cfg['systemd_run'],0700);
 $token=str_repeat('a',32);$checkout=$dir.'/repositories/previews/'.$token;mkdir($checkout,0700,true);mkdir($checkout.'/public');file_put_contents($checkout.'/public/index.html','certificate');file_put_contents($checkout.'/.pjas',json_encode($manifest));
 Proc::must(['/usr/bin/git','init','--quiet',$checkout]);Proc::must(['/usr/bin/git','add','.'],$checkout);Proc::must(['/usr/bin/git','-c','user.name=test','-c','user.email=test@example.com','commit','--quiet','-m','fixture'],$checkout);
 $sha=trim(Proc::must(['/usr/bin/git','rev-parse','HEAD'],$checkout));file_put_contents($checkout.'.json',json_encode(['repo'=>'https://github.com/test/cert','sha'=>$sha]));chmod($checkout.'.json',0600);
 $ops=new Components($cfg);$p=['service'=>'cert','token'=>$token];
 $plan=$ops->plan($p);check($plan['sha']===$sha,'レビューは読み込み済みコミットに固定する');
 rejects(fn()=>$ops->apply(['service'=>'cert','review'=>str_repeat('0',64)]));
 check($plan['missing_secrets']===['api'],'未配置の秘密情報を値なしで表示する');
 rejects(fn()=>$ops->apply(['service'=>'cert','review'=>$plan['review']]));
 check($ops->status(['service'=>'cert'])['phase']==='secrets_waiting','秘密情報待ち状態を保持して起動を拒否する');
 file_put_contents($dir.'/api-secret','private-test-value');chmod($dir.'/api-secret',0600);
 $ops->apply(['service'=>'cert','review'=>$plan['review']]);
 rejects(fn()=>$ops->apply(['service'=>'cert','review'=>$plan['review']]));
 check(Components::protectedSite($cfg,'cert'),'設定適用後も専用サイトとして維持する');
 rejects(fn()=>Components::guard($cfg,'cert','deploy.start'));rejects(fn()=>Components::guard($cfg,'cert','site.start'));rejects(fn()=>Components::guard($cfg,'cert','fs.read'));
 $release=$ops->releasePlan($p);$published=$ops->publish(['service'=>'cert','review'=>$release['review']]);check(is_link($dir.'/www/cert/current') && file_get_contents($dir.'/www/cert/current/public/index.html')==='certificate','固定Gitオブジェクトを検査して公開する');
 if (is_executable('/usr/bin/systemd-analyze')) { $verify=Proc::run(['/usr/bin/systemd-analyze','--user','verify',$dir.'/units/pjas-component-cert-ca.service'],null,30,null,['PATH'=>'/usr/bin:/bin','XDG_RUNTIME_DIR'=>$dir,'SYSTEMD_UNIT_PATH'=>'/usr/lib/systemd/system:'.$dir.'/units']); check($verify['code']===0 && !str_contains($verify['err'],'pjas-component-cert-ca.service:'),'生成unitをsystemd本体のパーサーで検証する（ユーザーモード）'.($verify['code']!==0?$verify['err']:'')); }
 check((fileperms($dir.'/www/cert/current/public/index.html')&0022)===0,'他ユーザーへのコード書き込み権限を付与しない');
 rejects(fn()=>$ops->action(['service'=>'cert','action'=>'start']));
 $ops->setup(['service'=>'cert']);$count=count(file($dir.'/tasks'));$ops->setup(['service'=>'cert']);check(count(file($dir.'/tasks'))===$count+1,'再試行は完了判定だけを実行しCAを再生成しない');
 $ops->action(['service'=>'cert','action'=>'start']);check($ops->status(['service'=>'cert'])['components']['ca']['ready'],'起動と準備完了を別々に確認する');
 // Simulate reboot: workers lose runtime secrets even when no FPM file exists.
 unlink($dir.'/active');cleanup($dir.'/run');mkdir($dir.'/run',0755);
 $recovered=$ops->recoverReadySites();
 check(($recovered['cert']['phase']??'')==='ready' && file_get_contents($dir.'/run/cert-ca/secrets/api')==='private-test-value','起動時にreadyのサイトだけを検証し秘密情報と実行領域を復帰する');
 if ($componentType==='php') check(is_file($dir.'/run/cert-ca/php-fpm.conf'),'OS再起動で消失したFPM設定を承認済みハッシュと照合して再生成する');
 $store=new ControlStore($cfg);$saved=$store->read('components-cert');
 $saved['policy']=str_repeat('0',64);$store->write('components-cert',$saved);unlink($dir.'/active');
 check(!empty($ops->recoverReadySites()['cert']['failed']) && !file_exists($dir.'/active'),'許可台帳のハッシュが不一致なら自動起動しない');
 $saved['policy']=hash('sha256',json_encode((new ComponentPolicy($cfg))->data,JSON_THROW_ON_ERROR));$store->write('components-cert',$saved);
 $unitPath=$dir.'/units/pjas-component-cert-ca.service';$unitBody=file_get_contents($unitPath);file_put_contents($unitPath,$unitBody."\n# changed\n");
 check(!empty($ops->recoverReadySites()['cert']['failed']) && !file_exists($dir.'/active'),'承認済みunitのハッシュが不一致なら自動起動しない');
 file_put_contents($unitPath,$unitBody);$store->write('components-cert',$saved);$ops->action(['service'=>'cert','action'=>'start']);
 $backup=$ops->backup(['service'=>'cert']);file_put_contents($dir.'/data/db','changed');
 $review=$ops->restorePlan(['service'=>'cert','backup'=>$backup['id']]);$ops->restore(['service'=>'cert','backup'=>$backup['id'],'review'=>$review['review']]);
 check(file_get_contents($dir.'/data/db')==='original' && !file_exists($dir.'/active'),'暗号化バックアップを復元して発行停止を保持する');
 check(!empty($ops->recoverReadySites()['cert']['skipped']) && !file_exists($dir.'/active'),'restore_holdはOS再起動後も起動しない');
 rejects(fn()=>$ops->action(['service'=>'cert','action'=>'start']));rejects(fn()=>$ops->resume(['service'=>'cert']));
 $ops->resume(['service'=>'cert','accept_history_loss'=>true]);$ops->action(['service'=>'cert','action'=>'start']);
 $ops->action(['service'=>'cert','action'=>'stop']);
 check(!empty($ops->recoverReadySites()['cert']['skipped']) && !file_exists($dir.'/active'),'明示停止したサイトはOS再起動後も停止を維持する');
 // Interrupted setup must never replay the create operation.
 $store=new ControlStore($cfg);$state=$store->read('components-cert');$state['setup']['ca.init']['state']='running';$state['phase']='setup_failed';$store->write('components-cert',$state);
 rejects(fn()=>$ops->setup(['service'=>'cert']));
 check(!str_contains(json_encode($ops->status(['service'=>'cert'])),'private-test-value'),'応答と監査に秘密値を返さない');
 $daemon=new \Pjas\Agentd\Ops\Daemon($cfg);
 rejects(fn()=>$daemon->save(['service'=>'cert','mode'=>'existing','unit'=>'pjas-component-cert-ca.service','label'=>'steal']));
 $policy=new ComponentPolicy($cfg);rejects(fn()=>$policy->storage('state','other','ca'));
 $catalog['storage']['overlap']=$catalog['storage']['state'];file_put_contents($cfg['component_catalog'],json_encode($catalog));
 rejects(fn()=>(new ComponentPolicy($cfg))->storage('state','cert','ca'));
 check(true,'別サイト参照・領域重複・中断後のCA再生成を拒否する');
} finally {cleanup($dir);}
