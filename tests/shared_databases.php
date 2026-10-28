<?php
declare(strict_types=1);
foreach (['OpException','Spec','Paths','Proc','JobLog','SharedDatabases'] as $f) require dirname(__DIR__).'/agent/lib/'.$f.'.php';
require dirname(__DIR__).'/agent/ops/Deploy.php';
require dirname(__DIR__).'/agent/ops/Service.php';
use Pjas\Agentd\SharedDatabases;
use Pjas\Agentd\Paths;
use Pjas\Agentd\JobLog;
use Pjas\Agentd\OpException;
use Pjas\Agentd\Spec;
use Pjas\Agentd\Ops\Deploy;
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "ok {$label}\n";}
function rejects(callable $f):void{try{$f();}catch(OpException){return;}throw new RuntimeException('拒否されませんでした');}
$dir=sys_get_temp_dir().'/pjas-db-'.bin2hex(random_bytes(6));mkdir($dir);
$paths=new Paths($dir);$name='demo';$old=$paths->release($name,'20261028-000001');$new=$paths->release($name,'20261028-000002');
foreach ([$old.'/data',$new.'/data',$paths->shared($name),$dir.'/jobs'] as $p) mkdir($p,0755,true);
$user=posix_getpwuid(posix_geteuid())['name'];$group=posix_getgrgid(posix_getegid())['name'];
$cfg=['web_user'=>$user,'web_group'=>$group];$helper=new SharedDatabases($cfg,$paths);$log=new JobLog(1,$dir.'/jobs');
try{
 $db=new SQLite3($old.'/data/site.sqlite');$db->exec('create table notes (body text)');$db->exec("insert into notes values ('latest data')");$db->close();
 $seed=new SQLite3($new.'/data/site.sqlite');$seed->exec('create table notes (body text)');$seed->exec("insert into notes values ('seed must not win')");$seed->close();
 file_put_contents($new.'/app.php','<?php');file_put_contents($new.'/seed.sql','SQL dump');mkdir($new.'/vendor');file_put_contents($new.'/vendor/sample.db','library sample');
 $withoutExt=new SQLite3($new.'/localdata');$withoutExt->exec('create table a (n)');$withoutExt->close();
 check(SharedDatabases::discover($new)===['data/site.sqlite','localdata'],'拡張子とSQLiteヘッダーで検出し、SQLダンプ・プログラム・vendorを除外');
 $files=array_merge(SharedDatabases::discover($old),SharedDatabases::discover($new));
 $helper->link($name,$new,$files,$old,$log);
 check(is_link($new.'/data/site.sqlite')&&is_link($old.'/data/site.sqlite'),'新旧のバージョンから同じDBへ接続');
 $db=new SQLite3($new.'/data/site.sqlite');
 check($db->querySingle('select body from notes')==='latest data','新バージョンの初期DBで公開中のデータを上書きしない');
 $db->exec("insert into notes values ('new write')");$db->close();
 $oldDb=new SQLite3($old.'/data/site.sqlite');check($oldDb->querySingle('select count(*) from notes')===2,'ロールバック先でも最新データを読める');$oldDb->close();
 $files=SharedDatabases::discover($paths->shared($name));$helper->link($name,$old,$files,$new,$log);
 check(is_link($old.'/localdata'),'以前のコードにない共有DBもロールバック先へ接続');
 // SQLiteのWALとSHMも実体の保存先で運用する。
 $db=new SQLite3($new.'/data/site.sqlite');$db->exec('pragma journal_mode=wal');$db->exec("insert into notes values ('WAL data')");
 check(is_file($paths->shared($name).'/data/site.sqlite-wal')&&!is_file($new.'/data/site.sqlite-wal'),'SQLiteの追加書き込みファイルも共有先に作成');$db->close();
 $orphan=$new.'/orphan.db';file_put_contents($orphan,'existing');file_put_contents($orphan.'-wal','wal');file_put_contents($orphan.'-shm','shm');
 $helper->link($name,$new,['orphan.db'],null,$log);
 check(file_get_contents($paths->shared($name).'/orphan.db-wal')==='wal'&&file_get_contents($paths->shared($name).'/orphan.db-shm')==='shm','初回移行で既存のWAL・SHMをまとめて引き継ぐ');
 mkdir($dir.'/outside');file_put_contents($dir.'/outside/leak.db','outside');symlink($dir.'/outside',$new.'/escape');
 check(!in_array('escape/leak.db',SharedDatabases::discover($new),true),'フォルダのシンボリックリンク先を自動走査しない');
 rejects(fn()=>$helper->link($name,$new,['escape/leak.db'],null,$log));
 check(file_get_contents($dir.'/outside/leak.db')==='outside','外部ファイルを移動・削除しない');
 $spec=Spec::service(['name'=>$name,'domain'=>'example.com','shared'=>['.env','@databases']]);
 check($spec['auto_databases']&&$spec['shared']===['.env'],'自動DBの設定をパスとして扱わず、通常の引き継ぎと分離');
 // 共有storage内のDBも公開時の手順で初期化できる。
 $deploy=(new ReflectionClass(Deploy::class))->newInstanceWithoutConstructor();
 (new ReflectionProperty($deploy,'cfg'))->setValue($deploy,$cfg);
 (new ReflectionProperty($deploy,'paths'))->setValue($deploy,$paths);
 mkdir($new.'/storage');file_put_contents($new.'/storage/app.db','persistent');file_put_contents($new.'/storage/upload.txt','initial upload');
 (new ReflectionMethod($deploy,'linkShared'))->invoke($deploy,$name,['shared'=>['storage'],'auto_databases'=>true],$new,$log);
 check(is_link($new.'/storage')&&file_get_contents($new.'/storage/app.db')==='persistent','明示した共有フォルダ内のDBも初回から保持');
 check(file_get_contents($new.'/storage/upload.txt')==='initial upload','DBの自動引き継ぎで初期のアップロードファイルも失わない');
 $overlap=Spec::service(['name'=>$name,'domain'=>'example.com','shared'=>['storage/app.db','storage','storage','@databases']]);
 check($overlap['shared']===['storage'],'共有フォルダとその中のDBを重複指定しても親にまとめる');
 (new ReflectionMethod($deploy,'linkShared'))->invoke($deploy,$name,$overlap,$new,$log);
 check(file_get_contents($new.'/storage/app.db')==='persistent','繰り返しのリンク設定で共有DBの実体を削除しない');
 (new \Pjas\Agentd\Ops\Service($cfg,$paths))->create(['service'=>['name'=>'fresh','shared'=>['.env','storage','@databases']]]);
 check(!file_exists($paths->shared('fresh').'/.env')&&!file_exists($paths->shared('fresh').'/storage'),'登録時に空の共有ファイルを作って初期データを隠さない');
 $fresh=$paths->release('fresh','20261028-000001');mkdir($fresh,0755,true);file_put_contents($fresh.'/.env','actual configuration');
 (new ReflectionMethod($deploy,'linkShared'))->invoke($deploy,'fresh',['shared'=>['.env','storage'],'auto_databases'=>true],$fresh,$log);
 check(file_get_contents($fresh.'/.env')==='actual configuration'&&is_link($fresh.'/storage')&&is_dir($paths->shared('fresh').'/storage'),'初回公開で実際の設定を引き継ぎ、空の共有フォルダも作成する');
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
