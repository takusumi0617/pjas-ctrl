<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\{EncryptedFile,Paths,Spec,SitePause};use Pjas\Agentd\Ops\{Backups,Service};
[$dir,$cfg]=fixture('backups');$paths=new Paths($cfg['www_root']);$spec=Spec::service(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'static','shared'=>['.env','@databases']]);
try{
 (new Service($cfg,$paths))->create(['service'=>$spec]);$rel=$paths->release('demo','20261102-000001');mkdir($rel,0755,true);symlink($rel,$paths->current('demo'));
 $db=new SQLite3($paths->shared('demo').'/app.sqlite');$db->exec('create table notes(body text)');$db->exec('pragma journal_mode=wal');$db->exec("insert into notes values ('snapshot')");file_put_contents($paths->shared('demo').'/.env','SECRET=value');
 $backups=new Backups($cfg,$paths);
 $missing=$paths->shared('demo').'/missing.sqlite';try{$backups->copyDataFile($missing,$dir.'/missing-copy');throw new Exception('missing input accepted');}catch(Pjas\Agentd\OpException $e){check(str_contains($e->getMessage(),'missing.sqlite')&&str_contains($e->getMessage(),'実行ユーザー: '.$cfg['web_user'])&&!is_file($dir.'/missing-copy'),'読取失敗は内容を表示せず対象・実行ユーザーを報告し一時出力を消去');}
 $b=$backups->create(['service'=>$spec]);
 check($b['files']===2&&$b['bytes']>0,'SQLiteの整合性あるコピーと共有ファイルを暗号化して保存');
 $db->exec("insert into notes values ('later')");$db->close();
 $payload=$dir.'/control/backups/demo/'.$b['id'].'.pjas';check(!str_contains(file_get_contents($payload),'SECRET=value'),'暗号化ファイルに秘密情報の平文を含めない');
 $key=base64_decode($backups->keyInfo([])['key']);EncryptedFile::decrypt($payload,$dir.'/plain.tar.gz',$key);check(filesize($dir.'/plain.tar.gz')>0,'暗号鍵があれば保存したアーカイブを復号できる');
 rejects(fn()=>EncryptedFile::decrypt($payload,$dir.'/wrong',random_bytes(32)));
 $data=file_get_contents($payload);file_put_contents($dir.'/truncated',substr($data,0,-4));rejects(fn()=>EncryptedFile::decrypt($dir.'/truncated',$dir.'/short',$key));
 rejects(fn()=>$backups->restore(['service'=>$spec,'id'=>$b['id'],'writers_stopped'=>true]));
 (new SitePause($cfg))->write('demo',['enabled'=>true,'code'=>503,'html'=>'pause']);
 $restored=$backups->restore(['service'=>$spec,'id'=>$b['id'],'writers_stopped'=>true,'job_id'=>1]);
 $db=new SQLite3($paths->shared('demo').'/app.sqlite');check($db->querySingle('select count(*) from notes')===1,'バックアップ時点のDB内容へ復元');$db->close();
 check($restored['safety_backup']!==$b['id']&&count($backups->listing(['service'=>$spec])['backups'])===2,'復元前にも現在のデータをバックアップ');
 check(file_get_contents($paths->shared('demo').'/.env')==='SECRET=value','共有した環境設定も復元');
 $download=$backups->download(['service'=>$spec,'id'=>$b['id'],'offset'=>0]);check(base64_decode($download['data'])===$data,'暗号化バックアップを分割してダウンロード');
 rejects(fn()=>$backups->download(['service'=>['name'=>'other'],'id'=>$b['id']]));
 file_put_contents($dir.'/key.txt',base64_encode($key));chmod($dir.'/key.txt',0600);$decoder=dirname(__DIR__).'/bin/pjas-decrypt-backup';
 $decoded=Pjas\Agentd\Proc::run([PHP_BINARY,$decoder,$payload,$dir.'/key.txt',$dir.'/export.tar.gz']);check($decoded['code']===0&&hash_file('sha256',$dir.'/export.tar.gz')===hash_file('sha256',$dir.'/plain.tar.gz'),'別サーバー用の復号コマンドで同じアーカイブを取り出せる');
 $exists=Pjas\Agentd\Proc::run([PHP_BINARY,$decoder,$payload,$dir.'/key.txt',$dir.'/export.tar.gz']);check($exists['code']!==0&&is_file($dir.'/export.tar.gz'),'復号コマンドは既存ファイルを削除・上書きしない');
 $tampered=$data;$tampered[50]=chr(ord($tampered[50])^1);file_put_contents($dir.'/tampered',$tampered);rejects(fn()=>EncryptedFile::decrypt($dir.'/tampered',$dir.'/tampered-plain',$key));
 for($i=0;$i<120;$i++){$oldId='20200101T000000-'.str_pad(dechex($i),16,'0',STR_PAD_LEFT);$base=$dir.'/control/backups/demo/'.$oldId;file_put_contents($base.'.json',json_encode(['id'=>$oldId,'service'=>'demo','created_at'=>'2020-01-01T00:00:00Z']));file_put_contents($base.'.pjas','old');touch($base.'.pjas',time()-86400*40);}
 $pruned=$backups->prune(['service'=>$spec,'keep'=>2,'days'=>30]);check(count($pruned['deleted'])===120&&count($backups->listing(['service'=>$spec])['backups'])===2,'100件を超える古いバックアップも整理し、最低保存数は維持する');

}finally{cleanup($dir);}
