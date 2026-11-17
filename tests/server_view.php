<?php
declare(strict_types=1);
namespace Pjas\Support {
 final class Config {public static function load(string $file):void {} public static function get(string $key,mixed $default=null):mixed{return $default;}}
}
namespace Pjas\Http {
 final class Auth {public static function user():array{return ['email'=>'fixture@example.test','role'=>'owner'];}public static function can(string $role):bool{return true;}}
}
namespace Pjas\Agent {
 final class AgentClient {public static bool $fail=false;public static function alive():bool{if(self::$fail)throw new \RuntimeException('fixture layout failure');return true;}}
}
namespace {
 require dirname(__DIR__).'/app/bootstrap.php';
 use Pjas\Http\View;use Pjas\Agent\AgentClient;
 $messages=[];
 $check=static function(bool $ok,string $message)use(&$messages):void{if(!$ok)throw new RuntimeException($message);$messages[]='ok '.$message;};
 $check(!class_exists(\Pjas\Shared\PhpRuntime::class,false),'別画面の事前読み込みに依存しない新しいリクエスト');
 $_SESSION=[];$_SERVER['REQUEST_URI']='/server';
 date_default_timezone_set('Asia/Tokyo');
 $data=['title'=>'サーバーとctrl','snapshot'=>['time'=>time(),'cpu'=>12,'memory'=>24,'uptime'=>86400,'ssh_failures'=>0,'units'=>[['loaded'=>true,'unit'=>'pjas-control-web.service','state'=>'active']]],'checks'=>[],'alerts'=>[],'error'=>'','last_tick'=>gmdate('Y-m-d H:i:s')];
 ob_start();View::render('server',$data);$html=ob_get_clean();
 $check(str_contains($html,'CPU（処理の混雑）')&&str_contains($html,'pjas-control-web.service')&&str_contains($html,'サーバーのお知らせ'),'復旧手順の後もサーバー画面全体を描画する');
 $check(!str_contains($html,'自動監視が未開始、または停止しています'),'UTCの監視時刻を日本時間設定でも期限切れと誤判定しない');
 $check(str_contains($html,'class="app"')&&!str_contains($html,'class="error-page"'),'管理画面のレイアウトだけを出力する');
 $check(class_exists(\Pjas\Shared\PhpRuntime::class,false),'本番のオートローダーが共通クラスを読み込む');
 $outer=ob_get_level();ob_start();echo 'outer-buffer';AgentClient::$fail=true;
 try{View::render('server',$data);throw new LogicException('expected layout failure');}catch(RuntimeException $e){$check($e->getMessage()==='fixture layout failure','レイアウトでの例外を上位へ渡す');}
 $check(ob_get_level()===$outer+1,'失敗した描画が開いたバッファだけを閉じる');
 $check(ob_get_clean()==='outer-buffer','途中の本文・レイアウトを外側へ漏らさない');AgentClient::$fail=false;
 ob_start();try{View::render('server',array_replace($data,['snapshot'=>['time'=>[]]]));throw new LogicException('expected template failure');}catch(TypeError){}
 $check(ob_get_clean()==='','本文テンプレートの例外でも部分的なHTMLを破棄する');
 ob_start();View::error(500);$error=ob_get_clean();$check(str_contains($error,'class="error-page"')&&!str_contains($error,'CPU（処理の混雑）'),'障害時は独立したエラー画面だけを返す');
 echo implode("\n",$messages),"\n";
}
