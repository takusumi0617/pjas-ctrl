<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Http\{Auth,View};use Pjas\Support\{Db,Audit};
use Pjas\Domain\{ServiceRepo,Deployer,JobRepo};use Pjas\Agent\{AgentClient,AgentException};
final class SiteLifecycleController
{
    public function index():void{
        foreach(Db::all('SELECT job_id FROM service_lifecycle WHERE job_id IS NOT NULL') as $r)JobRepo::sync((int)$r['job_id']);
        foreach(Db::all("SELECT s.*,l.state lifecycle_state,l.job_id lifecycle_job FROM services s JOIN service_lifecycle l ON l.service_id=s.id WHERE l.state<>'active'") as $s){
            try{$root=AgentClient::call('site.status',['service'=>Deployer::spec($s)],Auth::actor());$j=$s['lifecycle_job']?JobRepo::find((int)$s['lifecycle_job']):null;
                if($root)Db::run("UPDATE service_lifecycle SET state=?,expires=?,detail=? WHERE service_id=?",[$root['state'],(int)($root['expires']??0),$root['error']??'',$s['id']]);
                elseif(($j['state']??'')==='failed')Db::run("UPDATE service_lifecycle SET state='active' WHERE service_id=?",[$s['id']]);
            }catch(AgentException){}
        }
        $rows=Db::all('SELECT s.name,s.domain,l.* FROM service_lifecycle l JOIN services s ON s.id=l.service_id WHERE l.state<>? ORDER BY l.updated_at DESC',['active']);
        View::render('retired_sites',['title'=>'削除済みサイト','rows'=>$rows]);
    }
    public function form(array $a):void{
        $s=ServiceRepo::findOrFail($a['name']);$plan=[];$error='';
        try{$plan=AgentClient::call('site.plan',['service'=>Deployer::spec($s)],Auth::actor());}catch(AgentException $e){$error=$e->getMessage();}
        View::render('site_delete',['title'=>'サイトの削除','site'=>$s,'plan'=>$plan,'error'=>$error,'tasks'=>Db::all('SELECT name FROM scheduled_tasks WHERE service_id=?',[$s['id']]),'reservations'=>(int)Db::one("SELECT COUNT(*) n FROM publication_queue WHERE service_id=? AND state='queued'",[$s['id']])['n']]);
    }
    public function action(array $a):void{
        $s=ServiceRepo::find($a['name']);if(!$s){View::error(404);return;}$job=null;$action=$_POST['action']??'delete';$prior=null;
        try{
            Auth::reauthenticate((string)($_POST['password']??''),true);
            if(($_POST['confirm_name']??'')!==$s['name'])throw new \InvalidArgumentException('確認欄にサイト名をそのまま入力してください');
            if(!in_array($action,['delete','restore'],true))throw new \InvalidArgumentException('操作を確認してください');
            if($action==='delete'){ $plan=AgentClient::call('site.plan',['service'=>Deployer::spec($s)],Auth::actor());if(($_POST['mode']??'')==='delete'&&(!$plan['paused']||$plan['blockers']||empty($_POST['writers_stopped'])))throw new \InvalidArgumentException('先に公開を一時停止し、表示された関連設定を整理して、書き込み停止の確認にチェックしてください'); }
            foreach(Db::all("SELECT id FROM jobs WHERE service_id=? AND state IN ('queued','running')",[$s['id']]) as $j)JobRepo::sync((int)$j['id']);
            Db::conn()->exec('BEGIN IMMEDIATE');
            try{
                $prior=Db::one('SELECT * FROM service_lifecycle WHERE service_id=?',[$s['id']]);
                if($action==='delete'&&$prior&&$prior['state']!=='active')throw new \InvalidArgumentException('このサイトは処理済みか、作業中です');
                if($action==='restore'&&!in_array($prior['state']??'', ['deleted','detached','failed','deleting','restoring'],true))throw new \InvalidArgumentException('復元できるサイトではありません');
                if(Db::one("SELECT id FROM jobs WHERE service_id=? AND state IN ('queued','running') AND (?=0 OR type NOT IN ('site-delete','site-restore'))",[$s['id'],(int)($action==='restore')])||Db::one("SELECT id FROM publication_queue WHERE service_id=? AND state IN ('starting','running')",[$s['id']]))throw new \InvalidArgumentException('作業が実行中です。作業履歴で終了を確認してください');
                $mode=$action==='restore'?$prior['mode']:(string)($_POST['mode']??'');if(!in_array($mode,['delete','detach'],true))throw new \InvalidArgumentException('削除方法を選んでください');
                $job=JobRepo::create((int)$s['id'],'site-'.$action,Auth::actor(),$action==='restore'?'サイトを復元':'サイトの管理を終了');
                Db::run("INSERT INTO service_lifecycle(service_id,state,mode,job_id) VALUES (?,?,?,?) ON CONFLICT(service_id) DO UPDATE SET state=excluded.state,mode=excluded.mode,job_id=excluded.job_id,updated_at=datetime('now')",[$s['id'],$action==='restore'?'restoring':'deleting',$mode,$job]);
                Db::run("UPDATE publication_queue SET state='cancelled',detail='サイトの管理終了により取り消しました' WHERE service_id=? AND state='queued'",[$s['id']]);Db::conn()->exec('COMMIT');
            }catch(\Throwable $e){Db::conn()->exec('ROLLBACK');$job=null;throw $e;}
            AgentClient::call('site.start',['service'=>Deployer::spec($s),'action'=>$action,'mode'=>$mode,'job_id'=>$job,'fingerprint'=>(string)($_POST['fingerprint']??''),'writers_stopped'=>!empty($_POST['writers_stopped'])],Auth::actor());
            Audit::log(Auth::actor(),'site.'.$action,$s['name'],['job'=>$job,'mode'=>$mode]);View::redirect('/jobs/'.$job,'作業を開始しました。結果は「削除済みサイト」で確認できます');
        }catch(\InvalidArgumentException|AgentException $e){
            if($job){Db::run("UPDATE jobs SET state='failed',detail=?,finished_at=datetime('now') WHERE id=?",[$e->getMessage(),$job]);Db::run("UPDATE service_lifecycle SET state=?,detail=? WHERE service_id=?",[$prior['state']??'active',$e->getMessage(),$s['id']]);}
            View::redirect($action==='restore'?'/retired-sites':'/services/'.$s['name'].'/delete',$e->getMessage(),'err');
        }
    }
}
