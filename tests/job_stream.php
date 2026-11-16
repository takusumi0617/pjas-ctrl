<?php
declare(strict_types=1);
namespace Pjas\Http {
 final class Auth {
  public static function requireSite(string $name,string $role='viewer'):void {}
  public static function user():array{return ['id'=>1,'role'=>'owner'];}
  public static function canSite(string $name,string $role='viewer'):bool{return true;}
  public static function can(string $role):bool{return true;}
 }
}
namespace {
 require __DIR__.'/support/features.php';
 use Pjas\Support\{Config,Db};use Pjas\Domain\JobRepo;use Pjas\Http\Controllers\JobController;
 if(($argv[1]??'')==='stream') {
  Config::load($argv[2].'/config.php');$_GET['offset']=(int)$argv[4];
  (new JobController())->stream(['id'=>$argv[3]]);exit;
 }
 [$dir,$cfg]=fixture('job-stream');
 try {
  webDb($dir);$sid=Db::insert('INSERT INTO services(name,domain) VALUES (?,?)',['alpha','alpha.example.test']);
  $id=JobRepo::create($sid,'deploy','fixture');Db::run("UPDATE jobs SET state='succeeded' WHERE id=?",[$id]);
  $prefix="受信済み\n";$rest="続きのログ\n";file_put_contents(JobRepo::logPath($id),$prefix.$rest);
  foreach([strlen($prefix),99999] as $offset){
   $process=proc_open(array_merge([PHP_BINARY],php_ini_loaded_file()?['-c',php_ini_loaded_file()]:[],[__FILE__,'stream',$dir,(string)$id,(string)$offset]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$err==='','SSEを実際のコントローラで処理する');
   preg_match('/event: chunk\ndata: (.+)\n/',$out,$m);check(json_decode($m[1],true)===($offset===99999?$prefix.$rest:$rest),'受信済みのバイト位置からだけ再送し、切り詰め時は先頭へ戻る');
   check(str_contains($out,'event: done')&&str_contains($out,'id: '.strlen($prefix.$rest)),'最新のバイト位置と完了状態を送る');
  }
 } finally {cleanup($dir);}
}
