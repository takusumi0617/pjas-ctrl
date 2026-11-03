<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Http\Auth;use Pjas\Support\Db;use Pjas\Domain\Totp;
ob_start();session_start();[$dir,$cfg]=fixture('session-proof');webDb($dir);
try{
 $secret='JBSWY3DPEHPK3PXP';$id=Db::insert('INSERT INTO users(email,password_hash,role,totp_secret) VALUES (?,?,?,?)',['owner@example.com',password_hash('test-password-long',PASSWORD_DEFAULT),'owner',$secret]);
 check(Auth::attempt('owner@example.com','test-password-long')&&Auth::pending(),'二段階認証の途中のセッションを作成');
 Db::run('UPDATE users SET password_hash=? WHERE id=?',[password_hash('different-password',PASSWORD_DEFAULT),$id]);
 check(!Auth::completeSecondFactor(Totp::code($secret,(int)floor(time()/30))),'パスワード変更前の認証途中セッションを再利用できない');
 $codes=(new ReflectionMethod(\Pjas\Http\Controllers\SecurityController::class,'codes'))->invoke(new \Pjas\Http\Controllers\SecurityController(),$id);check(count($codes)===10&&strlen(str_replace('-','',$codes[0]))===32&&Auth::consumeRecovery($id,$codes[0])&&!Auth::consumeRecovery($id,$codes[0]),'新しい復旧コードは128bitで、一度だけ使用できる');
}finally{session_destroy();cleanup($dir);ob_end_flush();}
