<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Support\{Config,Db};use Pjas\Http\Auth;
[$dir,$cfg]=fixture('migration');
try{
 $db=new PDO('sqlite:'.$dir.'/legacy.db');$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,password_hash TEXT,role TEXT,totp_secret TEXT,created_at TEXT)");$db->exec("INSERT INTO users VALUES(1,'owner@example.com','unused','owner',NULL,'2020-01-01'),(2,'op@example.com','unused','operator',NULL,'2020-01-01')");$db=null;
 file_put_contents($dir.'/legacy-config.php','<?php return '.var_export(['db'=>$dir.'/legacy.db'],true).';');Config::load($dir.'/legacy-config.php');Db::migrate(dirname(__DIR__).'/db/schema.sql');Db::migrate(dirname(__DIR__).'/db/schema.sql');
 $users=Db::all('SELECT * FROM users ORDER BY id');check(count($users)===2&&$users[0]['role']==='owner'&&$users[1]['scope_all']===1&&$users[1]['enabled']===1&&$users[1]['totp_last_step']===-1,'既存DBの利用者とアクセス範囲を保ち、何度でも追加テーブルを適用できる');
 $id=Db::insert('INSERT INTO services(name,domain) VALUES (?,?)',['demo','demo.example.com']);check(Auth::userCanSite(2,$id,'operator'),'移行前の操作担当者は引き続き既存サイトを操作できる');
}finally{cleanup($dir);}
