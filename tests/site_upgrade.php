<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{SiteUpgrade,SiteUpgradeDb,SiteIsolation,ControlStore,SitePause,SiteLifecycleGuard};
use Pjas\Domain\ServiceRepo;
use Pjas\Support\Db;
[$dir,$cfg]=fixture('site-upgrade');webDb($dir);
try{
    $values=ServiceRepo::validate(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'php8.4-fpm','hooks'=>"/usr/bin/php8.4 artisan config:cache"],true);$sid=ServiceRepo::create($values);
    Db::run("INSERT INTO scheduled_tasks(service_id,name,schedule,command,enabled,created_by) VALUES (?,?,?,?,?,?)",[$sid,'job','* * * * *','/usr/bin/php8.4 worker.php',1,'test']);
    $taskId=(int)Db::one('SELECT id FROM scheduled_tasks')['id'];
    mkdir($cfg['www_root'].'/demo/releases/20261116-010101/public',0755,true);mkdir($cfg['www_root'].'/demo/shared',0700);symlink($cfg['www_root'].'/demo/releases/20261116-010101',$cfg['www_root'].'/demo/current');
    $source="[Service]\nType=simple\nUser={$cfg['web_user']}\nGroup={$cfg['web_group']}\nWorkingDirectory={$cfg['www_root']}/demo/current\nEnvironment=TOKEN=must-not-leak\nMemoryMax=128M\nExecStart=/usr/bin/php8.4 worker.php\n";
    $unit='worker.service';file_put_contents($cfg['units']??$cfg['daemon_unit_dir'].'/'.$unit,$source);
    $row=['service'=>'demo','unit'=>$unit,'label'=>'worker','managed'=>false,'follow_paths'=>true,'exec_start'=>'/usr/bin/php8.4 worker.php'];
    file_put_contents($cfg['daemon_dir'].'/registry.json',json_encode([$unit=>$row]));
    file_put_contents($cfg['cron_dir'].'/pjas-task-'.$taskId,'original cron');file_put_contents($cfg['cron_script_dir'].'/task-'.$taskId.'.sh','original script');
    $conf=$cfg['apache_sites'].'/pjas-demo.conf';file_put_contents($conf,'original apache');
    $states=[$unit=>['LoadState'=>'loaded','ActiveState'=>'active','UnitFileState'=>'enabled','User'=>$cfg['web_user'],'Group'=>$cfg['web_group']]];$calls=[];$failIsolate=false;$failHealth=false;$failDrain=false;$isolationCalls=0;
    $db=static fn($op,$name,$p)=>$op==='read'?SiteUpgradeDb::read($name):SiteUpgradeDb::change($name,$p);
    $operations=function(string $kind,array $args)use(&$states,&$calls,&$failIsolate,&$failHealth,&$failDrain,&$isolationCalls,$cfg,$dir,$unit,$source):mixed{
        $calls[]=$kind;
        if($kind==='systemctl'){
            $action=$args[0];$target=end($args);
            if($action==='cat'){$p=$cfg['daemon_unit_dir'].'/'.$target.'.d/zzzzzz-pjas-isolation.conf';$h=$cfg['daemon_unit_dir'].'/'.$target.'.d/zzzzzz-pjas-upgrade-hold.conf';return $source.(is_file($p)?file_get_contents($p):'').(is_file($h)?file_get_contents($h):'');}
            if($action==='show'){
                if(in_array('--property=Conditions',$args,true))return '';
                if(in_array('--property=DropInPaths',$args,true)){$p=$cfg['daemon_unit_dir'].'/'.$target.'.d/zzzzzz-pjas-upgrade-hold.conf';return is_file($p)?$p:'';}
                $st=$states[$target];if(is_file($cfg['daemon_unit_dir'].'/'.$target.'.d/zzzzzz-pjas-isolation.conf')){$st['User']=$st['Group']=SiteIsolation::identity('demo');$st['NoNewPrivileges']='yes';$st['SupplementaryGroups']=$st['AmbientCapabilities']=$st['CapabilityBoundingSet']='';}
                return implode("\n",array_map(fn($k,$v)=>$k.'='.$v,array_keys($st),$st));
            }
            if(in_array($action,['start','stop'],true))$states[$target]['ActiveState']=$action==='start'?'active':'inactive';
            if(in_array($action,['enable','disable'],true))$states[$target]['UnitFileState']=$action==='enable'?'enabled':'disabled';
            return '';
        }
        if($kind==='acl-save'){file_put_contents($args['file'],'saved ACL');return [];}
        if($kind==='acl-restore'||$kind==='preflight')return [];
        if($kind==='pause-state'){(new SitePause($cfg))->write('demo',$args['state']);return [];}
        if($kind==='pause'){(new SitePause($cfg))->write('demo',['enabled'=>$args['action']==='pause','code'=>503,'page_mode'=>'default','html'=>'paused']);return [];}
        if($kind==='scope')return $cfg+['isolation_site'=>'demo','isolation_runtime'=>'php8.5-fpm','site_upgrade_id'=>$args['cfg']['site_upgrade_id']];
        if($kind==='drain'){if($failDrain)throw new RuntimeException('running cron');return [];}
        if($kind==='isolate'){
            $isolationCalls++;if($failIsolate)throw new RuntimeException('injected isolate failure');
            check(json_decode(file_get_contents($cfg['daemon_dir'].'/registry.json'),true)===[],'関連付けを退避した状態で隔離を実行');
            check(!is_file($cfg['cron_dir'].'/pjas-task-1'),'隔離前にcronを停止');
            file_put_contents($cfg['apache_sites'].'/pjas-demo.conf','isolated apache');(new ControlStore($cfg))->write('isolation-demo',['runtime'=>'php8.5-fpm','status'=>'ready']);return ['isolated'=>true];
        }
        if($kind==='cron'){if($args['task']['enabled'])file_put_contents($cfg['cron_dir'].'/pjas-task-'.$args['task']['id'],'new cron');return [];}
        if($kind==='health')return ['ok'=>!$failHealth,'paused'=>false];
        throw new RuntimeException($kind);
    };
    $hold='/var/lib/pjas-control/never-start-test';
    check(SiteUpgrade::hasStartHold("[Unit]\nConditionPathExists=$hold\n[Service]\nUser=www-data\n",$hold),'Conditionsの評価状態に依存せず読み込む停止条件を確認');
    check(!SiteUpgrade::hasStartHold("[Unit]\nConditionPathExists=$hold\nConditionArchitecture=\n",$hold),'別のConditionの空代入による条件解除も検出');
    check(!SiteUpgrade::hasStartHold("[Unit]\n# ConditionPathExists=$hold\nConditionPathExists=|$hold\n",$hold),'コメントやOR条件だけでは起動禁止と扱わない');
    $upgrade=new SiteUpgrade($cfg,$db,$operations);
    Db::run("INSERT INTO jobs(service_id,type,state) VALUES (?,'deploy','queued')",[$sid]);$completed=(int)Db::conn()->lastInsertId();
    file_put_contents($cfg['job_dir'].'/'.$completed.'.json',json_encode(['state'=>'failed','detail'=>'completed earlier']));
    check(SiteUpgradeDb::read('demo')['busy']===0&&Db::one('SELECT state FROM jobs WHERE id=?',[$completed])['state']==='failed','agentが完了済みのジョブだけをDBへ同期する');
    Db::run("INSERT INTO jobs(service_id,type,state) VALUES (?,'deploy','running')",[$sid]);$running=(int)Db::conn()->lastInsertId();
    $pending=SiteUpgradeDb::read('demo');check($pending['busy']===1&&$pending['pending_jobs'][0]['id']===$running&&$pending['pending_jobs'][0]['agent_state']==='missing','状態ファイルがないジョブは失敗扱いせずIDを報告');
    Db::run('DELETE FROM jobs WHERE id=?',[$running]);
    $plan=$upgrade->plan('demo','php8.5-fpm');
    check(!str_contains(json_encode($plan),'must-not-leak')&&$states[$unit]['ActiveState']==='active','計画は機密値を表示せず稼働状態も変更しない');
    rejects(fn()=>$upgrade->apply($plan['id'],false,true));
    $failDrain=true;rejects(fn()=>$upgrade->apply($plan['id'],true,true));
    check($upgrade->status($plan['id'])['phase']==='quiescing'&&Db::one('SELECT enabled FROM scheduled_tasks')['enabled']==0,'実行済みcronが残る場合は停止状態で中断');
    rejects(fn()=>SiteLifecycleGuard::active($cfg,'demo'));
    $failDrain=false;$prepared=$upgrade->apply($plan['id'],true,true);
    check($prepared['phase']==='prepared'&&$states[$unit]['ActiveState']==='inactive','再開可能な段階を再開し、移行後もワーカーを停止');
    check(Db::one('SELECT runtime FROM services')['runtime']==='php8.5-fpm'&&Db::one('SELECT enabled FROM scheduled_tasks')['enabled']==0,'DBのPHPとcronを安全に更新');
    check(file_get_contents($cfg['daemon_unit_dir'].'/'.$unit)===$source,'元unitの環境設定・メモリ制限を上書きしない');
    $override=file_get_contents($cfg['daemon_unit_dir'].'/'.$unit.'.d/zzzzzz-pjas-isolation.conf');
    check(str_contains($override,'/usr/bin/php8.5 worker.php')&&str_contains($override,'SupplementaryGroups='),'同じunitの実行ユーザーとPHPだけをdrop-inで移行');
    $upgrade->apply($plan['id'],true,true);check($isolationCalls===1,'完了済みの隔離をapplyで重ねて実行しない');
    mkdir($cfg['www_root'].'/demo/current/server');file_put_contents($cfg['www_root'].'/demo/current/server/index.php','<?php');
    $routed=$upgrade->routing($plan['id'],'server/index.php');check($routed['routing']==='php-api-front-controller'&&$routed['paused'],'preparedのままAPI設定を追加し、再移行せず再開に備える');
    rejects(fn()=>$upgrade->routing($plan['id'],'../shared/private.php'));
    $failHealth=true;rejects(fn()=>$upgrade->activate($plan['id'],true));
    check($states[$unit]['ActiveState']==='inactive'&&(new SitePause($cfg))->read('demo')['enabled']&&!is_file($cfg['cron_dir'].'/pjas-task-'.$taskId),'HTTP検査失敗時はサイト・ワーカー・cronを再停止');
    $upgrade->rollback($plan['id'],true);
    check(Db::one('SELECT runtime FROM services')['runtime']==='php8.4-fpm'&&Db::one('SELECT enabled FROM scheduled_tasks')['enabled']==1,'公開前の設定とcronを復旧');
    check(file_get_contents($conf)==='original apache'&&file_get_contents($cfg['cron_dir'].'/pjas-task-'.$taskId)==='original cron'&&$states[$unit]['ActiveState']==='active','Apache・cron・元の稼働状態を復旧');
    check(!is_file($cfg['daemon_unit_dir'].'/'.$unit.'.d/zzzzzz-pjas-isolation.conf'),'復旧時に追加drop-inを削除');
    $upgrade->rollback($plan['id'],true);SiteLifecycleGuard::active($cfg,'demo');
    $failHealth=false;$new=$upgrade->plan('demo','php8.5-fpm');$upgrade->apply($new['id'],true,true);$upgrade->activate($new['id'],true);
    check($upgrade->status($new['id'])['phase']==='active'&&Db::one('SELECT enabled FROM scheduled_tasks')['enabled']==1&&$states[$unit]['ActiveState']==='active','CLIだけで移行と再開を完了');
    rejects(fn()=>$upgrade->rollback($new['id'],true));
    $later=$upgrade->plan('demo','php8.5-fpm');Db::run("UPDATE scheduled_tasks SET command='changed' WHERE id=?",[$taskId]);rejects(fn()=>$upgrade->apply($later['id'],true,true));
    check($upgrade->status($later['id'])['phase']==='planned','計画後のDB変更を上書きしない');
    $broken=$upgrade->plan('demo','php8.5-fpm');$failIsolate=true;rejects(fn()=>$upgrade->apply($broken['id'],true,true));
    check($upgrade->status('demo')['phase']==='isolating','隔離処理中の異常終了をサイト名でも確認できる');
    $count=$isolationCalls;rejects(fn()=>$upgrade->apply($broken['id'],true,true));check($isolationCalls===$count,'中断した隔離を盲目的に重複実行しない');
    $upgrade->rollback($broken['id'],true);check($upgrade->status($broken['id'])['phase']==='rolled-back'&&Db::one('SELECT command FROM scheduled_tasks')['command']==='changed','隔離途中の失敗でも計画時の設定を復旧する');
    $failIsolate=false;(new SitePause($cfg))->write('demo',['enabled'=>true,'code'=>403,'page_mode'=>'custom','html'=>'original custom']);
    $paused=$upgrade->plan('demo','php8.5-fpm');$upgrade->apply($paused['id'],true,true);$upgrade->activate($paused['id'],true);
    check((new SitePause($cfg))->read('demo')===['enabled'=>true,'code'=>403,'page_mode'=>'custom','html'=>'original custom'],'元々停止中のサイトは独自コード・HTMLを維持して停止する');
}finally{cleanup($dir);}
