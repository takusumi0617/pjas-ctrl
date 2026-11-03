<?php
declare(strict_types=1);
namespace Pjas\Agent { final class AgentClient {
 public static array $calls=[];public static bool $healthy=true;
 public static function call(string $op,array $params=[],string $actor='system'):array{
  self::$calls[]=[$op,$params,$actor];
  return match($op){
   'github.connection'=>['repo'=>'https://github.com/example/private','auth'=>'github_app'],
   'ops.notify'=>['sent'=>true], 'health.check'=>['ok'=>self::$healthy,'status'=>self::$healthy?200:500],
   'daemon.list'=>['daemons'=>[]], 'ops.resources'=>['total'=>100*1073741824,'free'=>50*1073741824],
   default=>[]};
 }
}}
namespace {
require __DIR__.'/support/features.php';
use Pjas\Support\Db;use Pjas\Domain\{Automation,PublicationQueue,SitePolicy,JobRepo,Alerting};use Pjas\Agent\AgentClient;
[$dir,$cfg]=fixture('automation');webDb($dir);$_SESSION=[];
try{
 $owner=Db::insert('INSERT INTO users(email,password_hash,role) VALUES (?,?,?)',['owner@example.com','unused','owner']);$id=Db::insert('INSERT INTO services(name,domain,runtime) VALUES (?,?,?)',['demo','demo.example.com','static']);$site=Db::one('SELECT * FROM services WHERE id=?',[$id]);
 SitePolicy::save($id,['branch'=>'main','webhook_enabled'=>1,'notifications'=>1,'backup_enabled'=>1,'backup_hours'=>1],$owner);
 $event=['delivery'=>'one','repo'=>'https://github.com/example/private','branch'=>'main','sha'=>str_repeat('a',40)];
 check(Automation::webhook($event)===1&&Automation::webhook($event)===0&&Automation::webhook(array_replace($event,['delivery'=>'two']))===0,'Webhookの再送と同じコミットの重複公開を防ぐ');
 $row=PublicationQueue::forSite($id)[0];check(json_decode($row['source'],true)['sha']===str_repeat('a',40),'自動公開の予約に確定したコミット番号を保存する');
 $first=Automation::tick();$second=Automation::tick();
 $starts=array_filter(AgentClient::$calls,fn($c)=>$c[0]==='deploy.start');$backups=array_filter(AgentClient::$calls,fn($c)=>$c[0]==='ops.run');
 check($first['started']===1&&$second['started']===0&&count($starts)===1&&count($backups)===1,'予約公開と定期バックアップを二重起動しない');
 $job=Db::one("SELECT * FROM jobs WHERE type='deploy'");file_put_contents(JobRepo::statusPath($job['id']),json_encode(['state'=>'failed','detail'=>'simulated failure']));Automation::tick();$alerts=(int)Db::one("SELECT COUNT(*) n FROM alerts WHERE kind='job'")['n'];Automation::tick();check($alerts===1&&(int)Db::one("SELECT COUNT(*) n FROM alerts WHERE kind='job'")['n']===1&&PublicationQueue::forSite($id)[0]['state']==='failed','失敗した作業を取り込み、一度だけ通知する');
 AgentClient::$healthy=false;Automation::tick();Automation::tick();AgentClient::$healthy=true;Automation::tick();$health=Db::all("SELECT * FROM alerts WHERE kind=? ORDER BY id",['health:'.$id]);check(count($health)===2&&(int)$health[0]['resolved']===0&&(int)$health[1]['resolved']===1,'障害中は通知を繰り返さず、復旧時に改めて通知する');
 Db::run("INSERT INTO service_lifecycle(service_id,state,mode) VALUES (?,'deleted','delete')",[$id]);
 $retired=PublicationQueue::reserve($site,['type'=>'git','sha'=>str_repeat('d',40)],gmdate('Y-m-d H:i:s'),$owner,'owner');$before=count(array_filter(AgentClient::$calls,fn($c)=>$c[0]==='deploy.start'));Automation::tick();
 check(Automation::webhook(array_replace($event,['delivery'=>'retired','sha'=>str_repeat('e',40)]))===0&&Db::one('SELECT state FROM publication_queue WHERE id=?',[$retired])['state']==='failed'&&count(array_filter(AgentClient::$calls,fn($c)=>$c[0]==='deploy.start'))===$before,'削除済みサイトは予約公開とWebhook自動公開の対象から除外する');
 Db::run('DELETE FROM service_lifecycle WHERE service_id=?',[$id]);
 $r=PublicationQueue::reserve($site,['type'=>'git','sha'=>str_repeat('b',40)],gmdate('Y-m-d H:i:s'),$owner,'owner');Db::run('UPDATE users SET enabled=0 WHERE id=?',[$owner]);Automation::tick();check(Db::one('SELECT state FROM publication_queue WHERE id=?',[$r])['state']==='failed'&&Automation::webhook(array_replace($event,['delivery'=>'three','sha'=>str_repeat('c',40)]))===0,'利用者の停止後は予約公開もWebhook自動公開も実行しない');
}finally{cleanup($dir);}
}
