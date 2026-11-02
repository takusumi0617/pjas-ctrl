<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\{ReleaseReview,Paths,Spec};use Pjas\Agentd\Ops\Service;
[$dir,$cfg]=fixture('review');$paths=new Paths($cfg['www_root']);$spec=Spec::service(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'static','shared'=>['.env','storage','@databases'],'hooks'=>['php artisan migrate --force']]);
try{
 (new Service($cfg,$paths))->create(['service'=>$spec]);$old=$paths->release('demo','20261102-000001');mkdir($old,0755,true);symlink($old,$paths->current('demo'));file_put_contents($old.'/index.html','old');file_put_contents($old.'/.env','PRODUCTION_SECRET');file_put_contents($old.'/old.html','delete');
 $review=new ReleaseReview($cfg,$paths);$token=bin2hex(random_bytes(16));$root=$review->directory($token);mkdir($root,0700);mkdir($root.'/tree',0700);file_put_contents($root.'/tree/index.html','new');file_put_contents($root.'/tree/new.html','add');file_put_contents($root.'/tree/.env','OTHER_SECRET');mkdir($root.'/tree/migrations');file_put_contents($root.'/tree/migrations/001.sql','alter table t add x;');
 $p=$review->save($token,$spec,['type'=>'upload'],['source'=>'upload:test.zip','sha256'=>'test','size'=>10],['label'=>'test release','auto_rollback'=>true]);
 check($p['counts']===['added'=>2,'modified'=>1,'deleted'=>1]&&!in_array('.env',array_column($p['changes'],'path'),true),'公開前に追加・変更・削除を比較し、DBと秘密情報は差分に出さない');
 check(count($p['warnings'])===1&&$review->publicPlan($p)['options']['label']==='test release','DB移行への注意と入力した公開設定を保持する');
 $diff=$review->diff(['service'=>$spec,'token'=>$token,'path'=>'index.html']);check(in_array(['-','old'],$diff['diff'],true)&&in_array(['+','new'],$diff['diff'],true),'変更前後のコードをGit差分で表示する');rejects(fn()=>$review->diff(['service'=>$spec,'token'=>$token,'path'=>'.env']));
 check($review->load('demo',$token,true)['token']===$token,'確認したファイルと公開中の状態を固定する');file_put_contents($old.'/.env','DATA_CHANGED');$review->load('demo',$token,true);
 file_put_contents($old.'/index.html','changed-on-server');rejects(fn()=>$review->load('demo',$token,true));file_put_contents($old.'/index.html','old');
 file_put_contents($root.'/tree/index.html','tampered');rejects(fn()=>$review->load('demo',$token));check(true,'確認後の本番コード変更と準備済みファイルの改変を拒否する');rejects(fn()=>$review->load('other',$token));
 $git=$dir.'/git';mkdir($git);Pjas\Agentd\Proc::must(['/usr/bin/git','init','--quiet',$git]);file_put_contents($git.'/index.html','first');Pjas\Agentd\Proc::must(['/usr/bin/git','-C',$git,'add','.']);Pjas\Agentd\Proc::must(['/usr/bin/git','-C',$git,'-c','user.name=Test','-c','user.email=test@example.com','commit','--quiet','-m','first']);$sha=trim(Pjas\Agentd\Proc::must(['/usr/bin/git','-C',$git,'rev-parse','HEAD']));file_put_contents($git.'/index.html','second');Pjas\Agentd\Proc::must(['/usr/bin/git','-C',$git,'-c','user.name=Test','-c','user.email=test@example.com','commit','-am','second','--quiet','--author=Test <test@example.com>'],null,10);
 $result=(new Pjas\Agentd\GitRepositories($cfg))->clone(['repo'=>$git,'ref'=>$sha],$dir.'/pinned');check($result['code']===0&&file_get_contents($dir.'/pinned/index.html')==='first','ブランチが進んでも通知されたコミットを固定して取得する');
}finally{cleanup($dir);}
