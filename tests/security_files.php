<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\{Paths,Spec};use Pjas\Agentd\Ops\{Service,Fs,Backups};
[$dir,$cfg]=fixture('security-files');$paths=new Paths($cfg['www_root']);$spec=Spec::service(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'static']);
try{
 (new Service($cfg,$paths))->create(['service'=>$spec]);file_put_contents($dir.'/victim','original');
 symlink($dir.'/victim',$paths->shared('demo').'/note.txt.pjas-tmp');
 (new Fs($cfg,$paths))->write(['service'=>$spec,'path'=>'shared/note.txt','content'=>'updated']);
 check(file_get_contents($dir.'/victim')==='original'&&file_get_contents($paths->shared('demo').'/note.txt')==='updated','予測可能な一時ファイルへのリンクを使って別ファイルを書き換えられない');
 symlink($dir.'/victim',$paths->shared('demo').'/linked');rejects(fn()=>(new Backups($cfg,$paths))->copyDataFile($paths->shared('demo').'/linked',$dir.'/copy'));
 check(!file_exists($dir.'/copy'),'バックアップ読み取りでリンクを拒否し、不完全なコピーを残さない');
 $vhost=new \Pjas\Agentd\Ops\Vhost($cfg,$paths);file_put_contents($cfg['apache_sites_enabled'].'/control.conf',"<VirtualHost *:443>\nServerName ctrl.example.com\nProxyPass / http://127.0.0.1:8184/\n</VirtualHost>\n");rejects(fn()=>$vhost->assertControlHostSafe("ServerName demo.example.com\nServerAlias *.example.com\n"));$vhost->assertControlHostSafe("ServerName demo.example.com\n");check(true,'リポジトリの公開設定や別名で管理画面のアドレスを乗っ取らない');
 rename($paths->shared('demo'),$dir.'/original-shared');symlink($dir,$paths->shared('demo'));rejects(fn()=>(new Service($cfg,$paths))->create(['service'=>$spec]));check(true,'管理用フォルダのリンクをたどって所有者を変更しない');
}finally{cleanup($dir);}
