<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Domain\{SitePresentation,SettingsChanges};
use Pjas\Agentd\{ControlStore,SitePause};
use Pjas\Agentd\Ops\{SiteOverview,UpgradeUi};
$now=strtotime('2026-11-17 00:00:00 UTC');$recent=gmdate('Y-m-d H:i:s',$now-60);
$site=['name'=>'alpha','runtime'=>'php8.5-fpm','state'=>'healthy','checked_at'=>$recent];
$obs=['pause'=>[],'isolation'=>['isolated'=>true],'component_site'=>false,'upgrade'=>null];
check(SitePresentation::summarize($site,[],$obs,$now)['healthy'],'新しいHTTP成功だけを正常と表示する');
check(!SitePresentation::summarize(array_replace($site,['checked_at'=>gmdate('Y-m-d H:i:s',$now-600)]),[],$obs,$now)['healthy'],'古い成功結果は正常件数に含めない');
check(SitePresentation::summarize($site,[],null,$now)['label']==='状態不明','公開状態を取得できないときは公開中と断定しない');
check(SitePresentation::summarize($site,[['ok'=>0,'detail'=>'worker停止','checked_at'=>$recent]],$obs,$now)['label']==='確認が必要','HTTP正常でもワーカーの異常を統合表示する');
check(SitePresentation::summarize($site,[],array_replace($obs,['pause'=>['enabled'=>true]]),$now)['label']==='一時停止中','意図した停止と正常稼働を区別する');
check(!SitePresentation::summarize($site,[['ok'=>1,'checked_at'=>gmdate('Y-m-d H:i:s',$now-600)]],$obs,$now)['healthy'],'処理の監視結果が古い場合も期限切れとする');
check(!SitePresentation::summarize($site,[],array_replace($obs,['component_site'=>true]),$now)['healthy'],'コンポーネントサイトは通常サイトの古いPHP・HTTP値で正常としない');
[$dir,$cfg]=fixture('site-overview');
try {
 webDb($dir);SettingsChanges::record(1,['domain'=>'old.test'],['domain'=>'new.test']);check(SettingsChanges::pending(1)['fields']===['domain'],'設定の未確認状態を利用者のセッション外に保持する');$pending=SettingsChanges::pending(1);SettingsChanges::record(1,['docroot'=>'public'],['docroot'=>'dist']);SettingsChanges::clearIfCurrent(1,$pending);check(SettingsChanges::pending(1)!==null,'反映中に別の変更が保存された場合は未反映表示を残す');SettingsChanges::clearIfCurrent(1,SettingsChanges::pending(1));check(SettingsChanges::pending(1)===null,'成功後に未確認状態を解消する');
 $store=new ControlStore($cfg);$store->write('isolation-alpha',['runtime'=>'php8.5-fpm','status'=>'ready']);
 $ops=new SiteOverview($cfg);$out=$ops->listing(['sites'=>['alpha']]);check($out['sites']['alpha']['isolation']['user']==='pj-'.substr(hash('sha256','alpha'),0,24),'実行ユーザーをroot管理状態から取得する');
 rejects(fn()=>$ops->listing(['sites'=>['../secret']]));rejects(fn()=>$ops->listing(['sites'=>array_fill(0,201,'alpha')]));
 $journalId=str_repeat('b',24);$store->write('site-upgrade-'.$journalId,['id'=>$journalId,'name'=>'alpha','runtime'=>'php8.5-fpm','phase'=>'prepared','bundle'=>['spec'=>['name'=>'alpha','domain'=>'alpha.example.test','runtime'=>'php8.4-fpm'],'tasks'=>[]],'workers'=>['worker.service'=>['unit_body'=>'private-fixture-value']],'files'=>[['body'=>'private-fixture-value']],'error'=>'private-fixture-value','created_at'=>gmdate('c')]);
 $public=$ops->listing(['sites'=>['alpha']]);check($public['sites']['alpha']['upgrade']['id']===$journalId&&!str_contains(json_encode($public),'private-fixture-value')&&!str_contains(json_encode($public),'"journal"'),'移行の公開要約にunit本文・秘密のエラー・root専用journalを含めない');
 $upgrade=new UpgradeUi($cfg);rejects(fn()=>$upgrade->activate(['service'=>'alpha','id'=>str_repeat('a',24),'confirm'=>true]));rejects(fn()=>$upgrade->activate(['service'=>'alpha','id'=>'../../x','confirm'=>true]));check(true,'未準備・他サイト・不正IDによる移行再開を拒否する');
}finally{cleanup($dir);}
