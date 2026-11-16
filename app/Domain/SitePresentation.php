<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Http\Auth;
use Pjas\Support\Db;

/** One status model shared by home, list and detail. A stale success is not current health. */
final class SitePresentation
{
    public const MAX_AGE=180;
    public static function fresh(?string $date,int $now): bool
    {
        if(!$date)return false;
        try{$time=(new \DateTimeImmutable($date,new \DateTimeZone('UTC')))->getTimestamp();}catch(\Exception){return false;}
        return $time<=$now+30 && $time>=$now-self::MAX_AGE;
    }
    public static function summarize(array $site,array $checks,?array $observed,?int $now=null): array
    {
        $now??=time();$component=!empty($observed['component_site']);$pause=$observed['pause']??null;
        $httpFresh=self::fresh($site['checked_at']??null,$now);
        $bad=array_values(array_filter($checks,static fn($c)=>!(bool)$c['ok']));
        $stale=array_filter($checks,static fn($c)=>!self::fresh($c['checked_at']??null,$now));
        $paused=!empty($pause['enabled']);$unknown=$observed===null;
        $phase=$observed['upgrade']['phase']??null;
        $migrating=$phase!==null&&!in_array($phase,['active','rolled-back'],true);
        $label='正常';$tone='t-ok';$priority=4;$reason='HTTPと登録済み処理の確認に成功';
        if($unknown){$label='状態不明';$tone='t-warn';$priority=1;$reason='公開状態を取得できません。過去の成功を正常と扱いません';}
        elseif($migrating){$label='移行の確認待ち';$tone='t-warn';$priority=0;$reason=$observed['upgrade']['last_error']??'実行環境で進捗と次の操作を確認してください';}
        elseif($paused){$label='一時停止中';$tone='t-warn';$priority=2;$reason='停止設定が有効です。アプリの正常稼働とは区別します';}
        elseif($bad){$label='確認が必要';$tone='t-err';$priority=0;$reason=$bad[0]['detail'];}
        elseif(($component&&!$checks)||(!$component&&!$httpFresh)||$stale){$label='確認期限切れ';$tone='t-warn';$priority=1;$reason='最新の監視結果がありません。監視の稼働を確認してください';}
        elseif(!$component&&($site['state']??'')!=='healthy'){$label='HTTP異常';$tone='t-err';$priority=0;$reason='期待するHTTP応答を確認できません';}
        $dates=array_values(array_filter(array_column($checks,'checked_at'),static fn($d)=>is_string($d)&&$d!==''));
        $checkedAt=$component?($dates?min($dates):null):($site['checked_at']??null);
        return ['label'=>$label,'tone'=>$tone,'priority'=>$priority,'reason'=>$reason,'healthy'=>$priority===4,'paused'=>$paused,'unknown'=>$unknown,
            'http'=>!$httpFresh?'未確認・期限切れ':(($site['state']??'')==='healthy'?'正常':'異常'),'checks'=>$checks,'observed'=>$observed,'component'=>$component,
            'checked_at'=>$checkedAt];
    }
    public static function collect(array $services): array
    {
        $observations=[];$error='';
        if($services)try{$observations=AgentClient::call('site.overview',['sites'=>array_column($services,'name')],Auth::actor())['sites'];}catch(AgentException $e){$error=$e->getMessage();}
        $ids=array_column($services,'id');$by=[];
        if($ids)foreach(Db::all('SELECT * FROM monitor_state WHERE service_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids) as $c)$by[$c['service_id']][]=$c;
        foreach($services as &$site)$site['presentation']=self::summarize($site,$by[$site['id']]??[],$observations[$site['name']]??null);
        unset($site);
        usort($services,static fn($a,$b)=>[$a['presentation']['priority'],$a['name']]<=>[$b['presentation']['priority'],$b['name']]);
        return ['services'=>$services,'observationError'=>$error];
    }
}
