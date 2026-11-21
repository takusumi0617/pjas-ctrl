<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Support\Db;
use Pjas\Http\Auth;
use Pjas\Agent\{AgentClient,AgentException};
final class Automation
{
    public static function webhook(array $verified): int
    {
        if(!empty($verified['ping'])||!empty($verified['ignored']))return 0;
        if(Db::run('INSERT OR IGNORE INTO webhook_deliveries(delivery) VALUES (?)',[$verified['delivery']])!==1)return 0;$queued=0;
        foreach(Db::all('SELECT s.*,p.settings,p.updated_by FROM services s JOIN site_policies p ON p.service_id=s.id WHERE s.id NOT IN (SELECT service_id FROM service_lifecycle WHERE state<>\'active\')') as $site){
            $policy=json_decode($site['settings'],true);if(empty($policy['webhook_enabled'])||($policy['branch']??'')!==$verified['branch'])continue;
            if(!Auth::userCanSite((int)$site['updated_by'],(int)$site['id'],'operator'))continue;
            $connection=AgentClient::call('github.connection',['service'=>Deployer::spec($site)],'webhook');
            if(($connection['repo']??'')!==$verified['repo'])continue;
            if(Db::run('INSERT OR IGNORE INTO webhook_commits(service_id,sha) VALUES (?,?)',[$site['id'],$verified['sha']])!==1)continue;
            try{PublicationQueue::reserve($site,['type'=>'git','sha'=>$verified['sha'],'options'=>['auto_rollback'=>true,'label'=>'GitHub '.$verified['branch'].' · '.substr($verified['sha'],0,8)]],gmdate('Y-m-d H:i:s'),(int)$site['updated_by'],'GitHub Webhook');$queued++;}
            catch(\Throwable $e){Db::run('DELETE FROM webhook_commits WHERE service_id=? AND sha=?',[$site['id'],$verified['sha']]);throw $e;}
        }
        return $queued;
    }
    public static function tick(?int $now=null): array
    {
        try {
            AgentClient::call('ping', [], 'monitor');
            $result = self::tickConnected($now);
            Alerting::check('host.agent', null, true, '監視エージェントとの接続が復旧しました');
            return $result;
        } catch (\Pjas\Agent\AgentConnectionException $e) {
            Alerting::check('host.agent', null, false, '監視エージェントへ接続できません。サイトの状態判定を保留しています', false);
            return ['started'=>0,'checked'=>0,'backups'=>0,'agent_unavailable'=>true];
        }
    }

    private static function tickConnected(?int $now=null): array
    {
        $now??=time();$stats=['started'=>0,'checked'=>0,'backups'=>0];
        if(!Db::one("SELECT name FROM app_state WHERE name='jobs.alerts.seeded'")){
            Db::run("INSERT OR IGNORE INTO job_notifications(job_id) SELECT id FROM jobs WHERE state IN ('succeeded','failed')");Db::run("INSERT INTO app_state(name,value) VALUES ('jobs.alerts.seeded','1')");
        }
        // 途中で止まった「開始確認中」を自動で再実行せず、既存ジョブへ結び直す。
        foreach(Db::all("SELECT * FROM publication_queue WHERE state='starting' AND publish_at<?",[gmdate('Y-m-d H:i:s',$now-600)]) as $row){
            $actor='queue:'.$row['id'].':'.$row['actor'];$job=Db::one('SELECT * FROM jobs WHERE actor=? ORDER BY id DESC LIMIT 1',[$actor]);
            if($job&&is_file(JobRepo::statusPath((int)$job['id'])))Db::run("UPDATE publication_queue SET state='running',job_id=? WHERE id=?",[$job['id'],$row['id']]);
            else Db::run("UPDATE publication_queue SET state='failed',detail='開始状態を確認できませんでした。作業履歴を確認してから予約を作り直してください' WHERE id=?",[$row['id']]);
        }
        foreach(Db::all("SELECT * FROM publication_queue WHERE state='queued' AND publish_at<=? ORDER BY publish_at,id LIMIT 25",[gmdate('Y-m-d H:i:s',$now)]) as $row){
            if(Db::one("SELECT id FROM publication_queue WHERE service_id=? AND state IN ('starting','running')",[$row['service_id']]))continue;
            if(Db::run("UPDATE publication_queue SET state='starting' WHERE id=? AND state='queued'",[$row['id']])!==1)continue;
            try{
                $site=ServiceRepo::findOrFail(Db::one('SELECT name FROM services WHERE id=?',[$row['service_id']])['name']);
                if(Db::one("SELECT service_id FROM service_lifecycle WHERE service_id=? AND state<>'active'",[$site['id']]))throw new \InvalidArgumentException('サイトの管理は終了しています');
                if(!Auth::userCanSite((int)$row['requested_by'],(int)$site['id'],'operator'))throw new \InvalidArgumentException('予約した利用者の公開権限が変更されたため実行しません');
                $source=json_decode($row['source'],true,512,JSON_THROW_ON_ERROR);$actor='queue:'.$row['id'].':'.$row['actor'];
                $job=match($source['type']){'prepared'=>Deployer::fromPrepared($site,$source['token'],$source['options']??[],$actor),'git'=>Deployer::fromGit($site,$source['sha'],$source['options']??[],$actor),default=>throw new \InvalidArgumentException('予約の取得元が不正です')};
                Db::run("UPDATE publication_queue SET state='running',job_id=? WHERE id=?",[$job,$row['id']]);$stats['started']++;
            }catch(\Pjas\Agent\AgentConnectionException $e){throw $e;}catch(\Throwable $e){Db::run("UPDATE publication_queue SET state='failed',detail=? WHERE id=?",[$e->getMessage(),$row['id']]);Alerting::event((int)$row['service_id'],'reservation','予約公開を開始できませんでした: '.$e->getMessage());}
        }
        foreach(Db::all("SELECT id FROM jobs WHERE state IN ('queued','running') ORDER BY id LIMIT 200") as $job)JobRepo::sync((int)$job['id']);
        foreach(Db::all("SELECT j.* FROM jobs j LEFT JOIN job_notifications n ON n.job_id=j.id WHERE n.job_id IS NULL AND j.state IN ('succeeded','failed') ORDER BY j.id LIMIT 200") as $job){
            if(Db::run('INSERT OR IGNORE INTO job_notifications(job_id) VALUES (?)',[$job['id']])!==1)continue;
            if($job['state']==='failed')Alerting::event($job['service_id']===null?null:(int)$job['service_id'],'job','作業 #'.$job['id'].' に失敗しました: '.$job['detail'],false,$job['service_id']===null||SitePolicy::get((int)$job['service_id'])['notifications']);
            if(in_array($job['type'],['backup','deploy','rollback','data-restore'],true)&&$job['service_id']){
                $site=Db::one('SELECT * FROM services WHERE id=?',[$job['service_id']]);if($site){$policy=SitePolicy::get((int)$site['id']);try{AgentClient::call('backup.prune',['service'=>Deployer::spec($site),'keep'=>$policy['retention_count'],'days'=>$policy['retention_days']],'monitor');}catch(AgentException){}}
            }
        }
        foreach(Db::all("SELECT q.id,j.state,j.detail FROM publication_queue q JOIN jobs j ON j.id=q.job_id WHERE q.state='running' AND j.state IN ('succeeded','failed')") as $row)Db::run('UPDATE publication_queue SET state=?,detail=? WHERE id=?',[$row['state']==='succeeded'?'done':'failed',$row['detail'],$row['id']]);
        ServerMonitoring::tick($now);
        $diskChecked=false;
        foreach(ServiceRepo::all() as $site){$policy=SitePolicy::get((int)$site['id']);
            DriftMonitoring::tick($site,(bool)$policy['notifications'],$now);
            $p=['service'=>Deployer::spec($site)];
            if (Db::one('SELECT service_id FROM component_sites WHERE service_id=?',[$site['id']])) { ComponentMonitoring::check($site,$policy); $stats['checked']++; continue; }
            try{$health=AgentClient::call('health.check',$p,'monitor');$message=$site['name'].': '.(!empty($health['paused'])?'公開停止中の応答':(!empty($health['ok'])?'正常に応答しています':'サイトの応答を確認できません（HTTP '.($health['status']?:'応答なし').'）'));
                Alerting::check('health:'.$site['id'],(int)$site['id'],!empty($health['ok']),$message,$policy['notifications']);ServiceRepo::setState((int)$site['id'],!empty($health['ok'])?'healthy':'unhealthy');$stats['checked']++;
                $daemons=AgentClient::call('daemon.list',['service'=>$site['name']],'monitor')['daemons'];foreach($daemons as $d){$active=$d['state']['ActiveState']??'';$expected=($d['state']['UnitFileState']??'')==='enabled';if(!$expected&&$active==='inactive')continue;
                    Alerting::check('daemon:'.$site['id'].':'.$d['unit'],(int)$site['id'],in_array($active,['active','activating'],true),$site['name'].' の常駐プログラム '.$d['unit'].' は '.$active.' です',$policy['notifications']);}
                if(!$diskChecked){$disk=AgentClient::call('ops.resources',$p,'monitor');$free=$disk['total']>0?$disk['free']/$disk['total']:0;Alerting::check('host.disk',null,$free>=0.1&&$disk['free']>=1024*1024*1024,'サイト領域の空き容量: '.round($free*100,1).'%（'.round($disk['free']/1073741824,1).'GB）');$diskChecked=true;}
                if($site['tls']){$cert=AgentClient::call('ops.certificate',$p,'monitor');Alerting::check('certificate:'.$site['id'],(int)$site['id'],$cert['valid']&&$cert['days']>=30,$site['name'].' の証明書: 残り'.$cert['days'].'日'.(!$cert['matches']?'・公開アドレスと不一致':''),$policy['notifications']);}
                if($policy['backup_enabled']&&$policy['backup_last']<=$now-$policy['backup_hours']*3600){
                    $job=JobRepo::create((int)$site['id'],'backup','定期バックアップ','共有データ・DBの定期保存');AgentClient::call('ops.run',$p+['kind'=>'backup','job_id'=>$job,'reason'=>'scheduled'],'monitor');$policy['backup_last']=$now;SitePolicy::write((int)$site['id'],$policy);$stats['backups']++;
                }
                Alerting::check('monitor:'.$site['id'],(int)$site['id'],true,$site['name'].' の運用確認を再開しました',$policy['notifications']);
            }catch(\Pjas\Agent\AgentConnectionException $e){throw $e;}catch(\Throwable $e){Alerting::check('monitor:'.$site['id'],(int)$site['id'],false,$site['name'].' の運用確認に失敗しました: '.$e->getMessage(),$policy['notifications']);}
        }
        $cleanup=Db::one("SELECT value FROM app_state WHERE name='preparations.cleanup'");
        if(!$cleanup||(int)$cleanup['value']<$now-86400)try{AgentClient::call('deploy.cleanup',[],'monitor');AgentClient::call('site.cleanup',[],'monitor');Db::run("INSERT INTO app_state(name,value) VALUES ('preparations.cleanup',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value",[(string)$now]);}catch(AgentException){}
        Db::run('DELETE FROM login_attempts WHERE reset_at<?',[$now-86400]);Db::run("DELETE FROM webhook_deliveries WHERE received_at<datetime('now','-30 days')");
        Db::run("INSERT INTO app_state(name,value) VALUES ('tick.last',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value",[gmdate('c',$now)]);return $stats;
    }
}
