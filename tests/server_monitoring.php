<?php
declare(strict_types=1);
namespace Pjas\Agent { final class AgentClient {
 public static array $calls=[];public static bool $healthy=false;
 public static function call(string $op,array $params=[],string $actor='system'):array{
 self::$calls[]=[$op,$params];return $op==='server.snapshot'?['checks'=>[['key'=>'disk','ok'=>self::$healthy,'message'=>'fixture disk']]]:['sent'=>true];
 }
}}
namespace Pjas\Http { final class View {
 public static array $data=[];
 public static function render(string $name,array $data=[]):void{self::$data=$data;}
 public static function error(int $status,string $message=''):void{throw new \RuntimeException('HTTP '.$status);}
}}
namespace {
require __DIR__.'/support/features.php';
use Pjas\Domain\ServerMonitoring;use Pjas\Support\Db;use Pjas\Agent\AgentClient;use Pjas\Http\Controllers\ServerController;
[$dir,$cfg]=fixture('server-monitoring');webDb($dir);$_SESSION=[];
try{
 $id=Db::insert('INSERT INTO users(email,password_hash,role) VALUES (?,?,?)',['owner@example.com','unused','owner']);
 ServerMonitoring::tick(time());ServerMonitoring::tick(time());AgentClient::$healthy=true;ServerMonitoring::tick(time());
 $alerts=Db::all("SELECT * FROM alerts WHERE kind='server.disk' ORDER BY id");check(count($alerts)===2&&!(bool)$alerts[0]['resolved']&&(bool)$alerts[1]['resolved'],'同じサーバー異常を繰り返し通知せず復旧通知を送る');
 Db::run('INSERT INTO server_alert_ack(alert_id,user_id) VALUES (?,?)',[$alerts[0]['id'],$id]);Db::run('DELETE FROM alerts WHERE id=?',[$alerts[0]['id']]);check(!Db::one('SELECT * FROM server_alert_ack'),'古い通知の削除時に確認記録も削除する');
 Db::run("INSERT INTO audit_log(actor,action,result) VALUES ('fixture','auth.login','failed')");ServerMonitoring::tick(time());check(str_contains(Db::one("SELECT detail FROM monitor_state WHERE check_key='server.ctrl-login'")['detail'],'1件'),'管理画面の認証失敗を成功や制限カウンターと混同しない');
 $retry=Db::insert("INSERT INTO alerts(kind,message,notification_error,created_at) VALUES ('server.retry','fixture','failed',?)",[gmdate('Y-m-d H:i:s',time()-600)]);
 ServerMonitoring::retryNotifications(time());check((bool)Db::one('SELECT notified_at FROM alerts WHERE id=?',[$retry])['notified_at'],'通知失敗後に時間を空けて再送する');
 $calls=count(AgentClient::$calls);ServerMonitoring::retryNotifications(time());check(count(AgentClient::$calls)===$calls,'送信済みの通知を重複して再送しない');
 $summary = new \Pjas\Domain\HealthNotifications();
 $before = count(AgentClient::$calls);
 $summary->check('test.first',null,false,'first');
 $summary->check('test.second',null,false,'second');
 $summary->check('test.silent',null,false,'silent',false);
 $summary->send();
 $calls = array_slice(AgentClient::$calls,$before);
 check(count($calls)===1&&$calls[0][0]==='ops.notify'&&str_contains($calls[0][1]['message'],'2件')
    &&!str_contains($calls[0][1]['message'],'silent')&&Db::one("SELECT id FROM alerts WHERE kind='test.silent'"),
    '同じ周期の異常を一通にまとめ、通知無効の異常も個別に記録する');
 $viewer=Db::insert('INSERT INTO users(email,password_hash,role) VALUES (?,?,?)',['viewer@example.com','unused','viewer']);$_SESSION['uid']=$viewer;
 try{(new ServerController())->index();throw new \Exception('閲覧が許可されました');}catch(\RuntimeException $e){check($e->getMessage()==='HTTP 403','閲覧専用利用者はサーバー情報を取得できない');}
 $_POST=['action'=>'restart','unit'=>'apache2.service'];$before=count(AgentClient::$calls);try{(new ServerController())->action();throw new \Exception('操作が許可されました');}catch(\RuntimeException $e){check($e->getMessage()==='HTTP 403'&&count(AgentClient::$calls)===$before,'閲覧専用利用者の再起動操作をRPC送信前に拒否する');}
 $_SESSION['uid']=$id;(new ServerController())->index();check(isset(\Pjas\Http\View::$data['snapshot']),'管理者はサーバーの状態を閲覧できる');

 $routes=file_get_contents(dirname(__DIR__).'/public/index.php');check(str_contains($routes,"get('/server',[\u{24}server,'index'],'owner')")&&str_contains($routes,"post('/server',[\u{24}server,'action'],'owner')"),'閲覧と操作の両方をサーバー管理者に限定する');
}finally{cleanup($dir);}
}
