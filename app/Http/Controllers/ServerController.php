<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Http\{Auth,View};
use Pjas\Support\{Db,Audit};
final class ServerController
{
    public function index(): void
    {
        Auth::require('owner');$snapshot=[];$error='';
        try{$snapshot=AgentClient::call('server.snapshot',[],Auth::actor());}catch(AgentException $e){$error=$e->getMessage();}
        $sites = Db::all("SELECT name,runtime FROM services WHERE id NOT IN (SELECT service_id FROM service_lifecycle WHERE state<>'active') ORDER BY name");
        $affected = ['apache2.service'=>array_column($sites,'name')];
        foreach ($sites as $site) {
            if (preg_match('/\Aphp[0-9]+\.[0-9]+-fpm\z/D', $site['runtime'])) {
                $affected[$site['runtime'].'.service'][] = $site['name'];
            }
        }
        $affectedByUnit = [];
        foreach ($snapshot['units'] ?? [] as $unit) {
            $dependencies = array_merge([$unit['unit']], $unit['stop_dependents'] ?? [], $unit['indirect_stop_dependents'] ?? []);
            $names = [];
            foreach ($dependencies as $dependency) {
                foreach ($affected[$dependency] ?? [] as $site) $names[$site] = true;
            }
            $affectedByUnit[$unit['unit']] = array_keys($names);
            sort($affectedByUnit[$unit['unit']], SORT_STRING);
        }
        View::render('server',['title'=>'サーバー','snapshot'=>$snapshot,'error'=>$error,'affected_sites'=>$affectedByUnit,
            'checks'=>Db::all("SELECT * FROM monitor_state WHERE service_id IS NULL ORDER BY ok,check_key"),
            'alerts'=>Db::all("SELECT a.*,k.alert_id AS acknowledged FROM alerts a LEFT JOIN server_alert_ack k ON k.alert_id=a.id WHERE a.service_id IS NULL ORDER BY a.id DESC LIMIT 50"),
            'last_tick'=>Db::one("SELECT value FROM app_state WHERE name='tick.last'")['value']??'']);
    }
    public function action(): void
    {
        Auth::require('owner');$action=(string)($_POST['action']??'');
        try{
            if($action==='restart'){
                Auth::reauthenticate((string)($_POST['password']??''),true);
                if(empty($_POST['confirmed']))throw new \InvalidArgumentException('サイトへの影響を確認してください');
                $unit=(string)($_POST['unit']??'');Audit::log(Auth::actor(),'server.restart.request','サーバー',['unit'=>$unit]);
                AgentClient::call('server.restart',['unit'=>$unit],Auth::actor());Audit::log(Auth::actor(),'server.restart.success','サーバー',['unit'=>$unit]);
            }elseif($action==='ack'){
                $id=(int)($_POST['id']??0);if(!Db::one('SELECT id FROM alerts WHERE id=? AND service_id IS NULL',[$id]))throw new \InvalidArgumentException('お知らせが見つかりません');
                Db::run('INSERT OR IGNORE INTO server_alert_ack(alert_id,user_id) VALUES (?,?)',[$id,Auth::user()['id']]);Audit::log(Auth::actor(),'server.alert.ack','サーバー',['id'=>$id]);
            }else throw new \InvalidArgumentException('操作を選んでください');
            View::redirect('/server',$action==='ack'?'確認済みにしました。異常そのものが解消されたことを示すものではありません。':'再起動が完了しました。次の自動確認で状態を確認してください。');
        }catch(AgentException|\InvalidArgumentException $e){if($action==='restart')Audit::log(Auth::actor(),'server.restart.failed','サーバー',['unit'=>substr((string)($_POST['unit']??''),0,100)],'failed');View::redirect('/server',$e->getMessage(),'err');}
    }
}
