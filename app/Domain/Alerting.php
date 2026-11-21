<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Support\Db;
use Pjas\Agent\{AgentClient,AgentException};
final class Alerting
{
    public static function event(?int $service,string $kind,string $message,bool $resolved=false,bool $notify=true): int
    {
        $id=Db::insert('INSERT INTO alerts(service_id,kind,message,resolved) VALUES (?,?,?,?)',[$service,$kind,mb_substr($message,0,4000),(int)$resolved]);
        if($notify)try{$r=AgentClient::call('ops.notify',['message'=>($resolved?'【復旧】':'【確認してください】').$message],'monitor');if(!empty($r['sent']))Db::run("UPDATE alerts SET notified_at=datetime('now') WHERE id=?",[$id]);}
        catch(AgentException $e){Db::run('UPDATE alerts SET notification_error=? WHERE id=?',[$e->getMessage(),$id]);}
        return $id;
    }
    public static function check(string $key,?int $service,bool $ok,string $message,bool $notify=true): bool
    {
        $old=Db::one('SELECT ok FROM monitor_state WHERE check_key=?',[$key]);
        Db::run("INSERT INTO monitor_state(check_key,service_id,ok,detail) VALUES (?,?,?,?) ON CONFLICT(check_key) DO UPDATE SET ok=excluded.ok,detail=excluded.detail,checked_at=datetime('now')",[$key,$service,(int)$ok,$message]);
        if ((!$old && !$ok) || ($old && (bool)$old['ok'] !== $ok)) {
            self::event($service,$key,$message,$ok,$notify);
            return true;
        }
        return false;
    }
}
