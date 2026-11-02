<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Support\{Db,Secrets,RateLimit,Time};use Pjas\Http\Auth;use Pjas\Domain\{Totp,ServiceRepo,JobRepo,PublicationQueue};
ob_start();session_start();[$dir,$cfg]=fixture('access');webDb($dir);
try{
 Db::migrate(dirname(__DIR__).'/db/schema.sql');
 $pw='long-test-password';$hash=password_hash($pw,PASSWORD_DEFAULT);
 $owner=Db::insert('INSERT INTO users(email,password_hash,role) VALUES (?,?,?)',['owner@example.com',$hash,'owner']);
 $op=Db::insert('INSERT INTO users(email,password_hash,role,scope_all) VALUES (?,?,?,0)',['op@example.com',$hash,'operator']);
 $viewer=Db::insert('INSERT INTO users(email,password_hash,role,scope_all) VALUES (?,?,?,0)',['viewer@example.com',$hash,'viewer']);
 $a=Db::insert('INSERT INTO services(name,domain) VALUES (?,?)',['alpha','alpha.example.com']);$b=Db::insert('INSERT INTO services(name,domain) VALUES (?,?)',['beta','beta.example.com']);
 Db::run('INSERT INTO service_access VALUES (?,?,?)',[$op,$a,'operator']);Db::run('INSERT INTO service_access VALUES (?,?,?)',[$op,$b,'viewer']);Db::run('INSERT INTO service_access VALUES (?,?,?)',[$viewer,$a,'operator']);
 check(Auth::userCanSite($owner,$b,'owner')&&Auth::userCanSite($op,$a,'operator')&&!Auth::userCanSite($op,$b,'operator')&&!Auth::userCanSite($viewer,$a,'operator'),'全体権限とサイトごとの閲覧・操作権限を組み合わせる');
 $_SESSION['uid']=$viewer;check(count(ServiceRepo::all())===1,'許可されていないサイトを一覧に出さない');
 JobRepo::create($b,'deploy','x','other');JobRepo::create($a,'deploy','x','own');check(count(JobRepo::recent())===1,'別サイトの作業履歴を見せない');
 $site=Db::one('SELECT * FROM services WHERE id=?',[$a]);rejects(fn()=>PublicationQueue::reserve($site,['type'=>'git','sha'=>str_repeat('a',40)],gmdate('Y-m-d H:i:s'),$viewer,'viewer'));
 $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';check(Totp::code($secret,1)==='287082','TOTPはRFC 6238の既知ベクトルと一致する');
 $enc=Secrets::encrypt($secret);check($enc!==$secret&&Secrets::decrypt($enc)===$secret&&(fileperms($dir.'/security.key')&0777)===0600,'認証アプリの秘密情報を専用鍵で暗号化して保存');
 Db::run('UPDATE users SET totp_secret=? WHERE id=?',[$enc,$op]);$_SESSION=[];
 check(Auth::attempt('op@example.com',$pw)&&Auth::user()===null&&Auth::pending(),'パスワードだけでは二段階認証利用者をログインさせない');
 $code=Totp::code($secret,(int)floor(time()/30));check(Auth::completeSecondFactor($code)&&Auth::user()['id']===$op,'認証アプリのコードを確認してログインする');
 $_SESSION=[];Auth::attempt('op@example.com',$pw);check(!Auth::completeSecondFactor($code),'同じ認証コードの再利用を拒否する');
 $recovery='AABBCCDDEEFF';Db::run('INSERT INTO recovery_codes VALUES (?,?)',[$op,hash('sha256',$recovery)]);check(Auth::completeSecondFactor('AABB-CCDD-EEFF')&&!Auth::consumeRecovery($op,$recovery),'復旧コードは一度だけ使用できる');
 Db::run('UPDATE users SET enabled=0 WHERE id=?',[$op]);check(Auth::user()===null&&!Auth::userCanSite($op,$a,'viewer'),'停止した利用者は既存のセッションも操作できない');
 check(RateLimit::hit('unit',2,300)&&RateLimit::hit('unit',2,300)&&!RateLimit::hit('unit',2,300),'パスワード・認証コードの試行回数を制限する');
 $now=strtotime('2026-11-02 00:00:00 UTC');check(Time::reservation('2026-11-02T10:00',$now)==='2026-11-02 01:00:00','予約日時は日本時間からUTCへ変換する');rejects(fn()=>Time::reservation('2026-11-02T08:00',$now));
}finally{session_destroy();cleanup($dir);ob_end_flush();}
