<?php
declare(strict_types=1);
namespace Pjas\Agentd;
use Pjas\Support\Db;
use Pjas\Domain\Deployer;
use Pjas\Shared\PhpRuntime;

/** Executed as the management DB owner, so SQLite sidecar ownership stays intact. */
final class SiteUpgradeDb
{
    public static function read(string $name): array
    {
        $name=Spec::name($name);
        $s=Db::one("SELECT s.* FROM services s LEFT JOIN service_lifecycle l ON l.service_id=s.id WHERE s.name=? AND (l.state IS NULL OR l.state='active')",[$name]);
        if(!$s)throw new OpException('有効な登録サイトがありません');
        return ['service'=>$s,'spec'=>Deployer::spec($s),'tasks'=>Db::all('SELECT t.*,? AS service FROM scheduled_tasks t WHERE service_id=? ORDER BY id',[$name,$s['id']]),'busy'=>(int)Db::one("SELECT count(*) AS n FROM jobs WHERE service_id=? AND state NOT IN ('succeeded','failed')",[$s['id']])['n']];
    }
    public static function fields(array $bundle,string $mode,string $runtime): array
    {
        $s=array_intersect_key($bundle['service'],array_flip(['runtime','hooks','extra_conf','allow_override']));
        $tasks=[];
        foreach($bundle['tasks'] as $t)$tasks[]=['id'=>(int)$t['id'],'name'=>$t['name'],'schedule'=>$t['schedule'],'command'=>$t['command'],'enabled'=>(int)$t['enabled']];
        if(in_array($mode,['migrated','activate','hold'],true)){
            $s=array_replace($s,['runtime'=>$runtime,'hooks'=>PhpRuntime::command($s['hooks'],$runtime),'extra_conf'=>'','allow_override'=>'None']);
            foreach($tasks as &$t)$t['command']=PhpRuntime::command($t['command'],$runtime);unset($t);
        }
        if(in_array($mode,['quiesce','migrated','hold'],true))foreach($tasks as &$t)$t['enabled']=0;
        return ['service'=>$s,'tasks'=>$tasks];
    }
    public static function change(string $name,array $payload): array
    {
        $original=$payload['original'];$runtime=$payload['runtime'];$mode=$payload['mode'];
        if(!in_array($mode,['quiesce','migrated','activate','rollback','hold'],true)||!in_array($runtime,PhpRuntime::RUNTIMES,true))throw new OpException('DB更新の種類が不正です');
        $pdo=Db::conn();$pdo->exec('BEGIN IMMEDIATE');
        try{
            $current=self::read($name);$actual=self::fields($current,'original',$runtime);
            $allowed=match($mode){'quiesce'=>['original','quiesce'],'migrated'=>['quiesce','migrated'],'activate'=>['migrated','activate'],'hold'=>['migrated','activate'],'rollback'=>['original','quiesce','migrated','activate']};
            $matches=false;foreach($allowed as $candidate)if($actual==self::fields($original,$candidate,$runtime))$matches=true;
            if(!$matches)throw new OpException('移行中にサイト設定またはcronが変更されました。上書きせず停止します');
            $target=self::fields($original,$mode==='rollback'?'original':$mode,$runtime);$s=$target['service'];
            Db::run('UPDATE services SET runtime=?,hooks=?,extra_conf=?,allow_override=? WHERE id=?',[$s['runtime'],$s['hooks'],$s['extra_conf'],$s['allow_override'],$current['service']['id']]);
            foreach($target['tasks'] as $t)Db::run('UPDATE scheduled_tasks SET command=?,enabled=?,updated_at=datetime(\'now\') WHERE id=? AND service_id=?',[$t['command'],$t['enabled'],$t['id'],$current['service']['id']]);
            $pdo->exec('COMMIT');return ['ok'=>true];
        }catch(\Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
    }
}
