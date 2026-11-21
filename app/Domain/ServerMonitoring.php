<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Agent\AgentClient;
use Pjas\Support\Db;
final class ServerMonitoring
{
    public static function retryNotifications(int $now): void
    {
        // Tick is serialized by bin/pjas-tick. Bound attempts and time spent per run.
        foreach(Db::all("SELECT a.*,COALESCE(r.attempts,0) AS attempts FROM alerts a LEFT JOIN notification_retries r ON r.alert_id=a.id WHERE a.notified_at IS NULL AND a.notification_error<>'' AND a.created_at>? AND a.created_at<=? AND COALESCE(r.attempts,0)<5 AND COALESCE(r.last_attempt,0)<=? ORDER BY a.id LIMIT 5",[gmdate('Y-m-d H:i:s',$now-86400),gmdate('Y-m-d H:i:s',$now-300),$now-300]) as $a){
            if($a['service_id']!==null&&!SitePolicy::get((int)$a['service_id'])['notifications'])continue;
            Db::run('INSERT INTO notification_retries(alert_id,attempts,last_attempt) VALUES (?,1,?) ON CONFLICT(alert_id) DO UPDATE SET attempts=attempts+1,last_attempt=excluded.last_attempt',[$a['id'],$now]);
            try{$r=AgentClient::call('ops.notify',['message'=>($a['resolved']?'【復旧・再送】':'【確認してください・再送】').$a['message']],'monitor');
                if(!empty($r['sent']))Db::run("UPDATE alerts SET notified_at=datetime('now'),notification_error='' WHERE id=?",[$a['id']]);
            }catch(\Pjas\Agent\AgentException $e){Db::run('UPDATE alerts SET notification_error=? WHERE id=?',[$e->getMessage(),$a['id']]);}
        }
    }
    public static function tick(int $now, ?HealthNotifications $notifications = null): array
    {
        try{
            $snapshot=AgentClient::call('server.snapshot',[],'monitor');
            if(!isset($snapshot['checks'])||!is_array($snapshot['checks']))throw new \RuntimeException('監視応答が不正です');
            foreach ($snapshot['checks'] as $c) {
                if ($notifications && str_starts_with($c['key'], 'service.')) {
                    $notifications->check('server.'.$c['key'], null, (bool)$c['ok'], $c['message']);
                } else {
                    Alerting::check('server.'.$c['key'], null, (bool)$c['ok'], $c['message']);
                }
            }
            $keys=array_merge(['server.collector','server.ctrl-login'],array_map(static fn($c)=>'server.'.$c['key'],$snapshot['checks']));
            foreach(Db::all("SELECT check_key FROM monitor_state WHERE check_key LIKE 'server.%'") as $row)if(!in_array($row['check_key'],$keys,true))Db::run('DELETE FROM monitor_state WHERE check_key=?',[$row['check_key']]);
            Alerting::check('server.collector',null,true,'サーバー監視を再開しました。');
        }catch(\Throwable $e){Alerting::check('server.collector',null,false,'サーバーの状態を取得できません。作業プログラムの接続を確認してください。');return [];}
        $failed=(int)(Db::one("SELECT COUNT(*) AS n FROM audit_log WHERE action IN ('auth.login','auth.second-factor') AND result='failed' AND at>?",[gmdate('Y-m-d H:i:s',$now-600)])['n']??0);
        Alerting::check('server.ctrl-login',null,$failed<20,'管理画面への認証失敗: 直近10分に記録された失敗が'.$failed.'件。利用者の操作か確認してください。');
        foreach(Db::all('SELECT s.id,s.name,p.settings FROM services s JOIN site_policies p ON p.service_id=s.id WHERE s.id NOT IN (SELECT service_id FROM service_lifecycle WHERE state<>\'active\')') as $site){
            $policy=json_decode($site['settings'],true)?:[];if(empty($policy['backup_enabled'])){Db::run('DELETE FROM monitor_state WHERE check_key=?',['backup.fresh:'.$site['id']]);continue;}
            $last=Db::one("SELECT finished_at FROM jobs WHERE service_id=? AND type='backup' AND state='succeeded' ORDER BY finished_at DESC LIMIT 1",[$site['id']]);
            $ok=$last&&strtotime($last['finished_at'].' UTC')>$now-((int)($policy['backup_hours']??24)*3600+7200);
            Alerting::check('backup.fresh:'.$site['id'],(int)$site['id'],(bool)$ok,$site['name'].' の定期バックアップ'.($ok?'は保存済みです。':'の成功記録が予定間隔内にありません。バックアップの履歴を確認してください。'),(bool)($policy['notifications']??true));
        }
        self::retryNotifications($now);
        Db::run("DELETE FROM alerts WHERE created_at<datetime('now','-90 days')");
        return $snapshot;
    }
}
