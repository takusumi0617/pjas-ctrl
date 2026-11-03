<?php
declare(strict_types=1);

namespace Pjas\Http;

use Pjas\Support\Audit;
use Pjas\Support\Db;
use Pjas\Support\Secrets;
use Pjas\Support\RateLimit;
use Pjas\Domain\Totp;

final class Auth
{
    private const RANK = ['viewer' => 1, 'operator' => 2, 'owner' => 3];

    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) {
            return null;
        }
        $u=Db::one('SELECT id,email,role,scope_all,password_hash,totp_secret,auth_version FROM users WHERE id=? AND enabled=1',[$_SESSION['uid']]);
        if(!$u)return null;
        if(PHP_SAPI!=='cli'&&(!isset($_SESSION['auth_proof'])||!hash_equals(self::proof($u),$_SESSION['auth_proof']))){unset($_SESSION['uid'],$_SESSION['reauth_at'],$_SESSION['auth_proof']);return null;}
        unset($u['password_hash'],$u['totp_secret'],$u['auth_version']);return $u;
    }

    private static function proof(array $u):string{return hash('sha256',json_encode([$u['password_hash'],$u['totp_secret']??null,$u['auth_version']??0],JSON_THROW_ON_ERROR));}
    public static function refreshProof(int $id):void{
        if((int)($_SESSION['uid']??0)!==$id)throw new \InvalidArgumentException('ログイン状態を確認してください');
        $row=Db::one('SELECT password_hash,totp_secret,auth_version FROM users WHERE id=? AND enabled=1',[$id]);
        if(!$row)throw new \InvalidArgumentException('ログイン状態を確認してください');$_SESSION['auth_proof']=self::proof($row);
    }
    public static function actor(): string
    {
        return self::user()['email'] ?? 'anonymous';
    }

    public static function attempt(string $email, string $password): bool
    {
        if(strlen($email)>254||strlen($password)>1024)return false;
        $email=strtolower(trim($email));$bucket='login:'.($_SERVER['REMOTE_ADDR']??'cli').':'.$email;
        if(!RateLimit::hit('login-ip:'.($_SERVER['REMOTE_ADDR']??'cli'),60,300)||!RateLimit::hit($bucket,12,300))return false;
        $u = Db::one('SELECT id, password_hash, totp_secret, auth_version FROM users WHERE lower(email) = ? AND enabled=1', [$email]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Audit::log($email, 'auth.login', '', [], 'failed');
            return false;
        }
        RateLimit::clear($bucket);
        unset($_SESSION['pending_uid'],$_SESSION['pending_at'],$_SESSION['totp_setup'],$_SESSION['totp_setup_at'],$_SESSION['new_recovery_codes']);
        $_SESSION['auth_proof']=self::proof($u);
        if(!empty($u['totp_secret'])){unset($_SESSION['uid']);$_SESSION['pending_uid']=(int)$u['id'];$_SESSION['pending_at']=time();return true;}
        session_regenerate_id(true);
        $_SESSION['reauth_at']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));
        $_SESSION['uid'] = (int) $u['id'];
        Audit::log($email, 'auth.login');
        return true;
    }

    public static function pending(): bool
    {
        if(!empty($_SESSION['pending_uid'])&&($_SESSION['pending_at']??0)>time()-600)return true;
        unset($_SESSION['pending_uid'],$_SESSION['pending_at']);return false;
    }
    public static function completeSecondFactor(string $code): bool
    {
        if(!self::pending())return false;$id=(int)$_SESSION['pending_uid'];
        if(!RateLimit::hit('totp-user:'.$id,30,300)||!RateLimit::hit('totp:'.$id.':'.($_SERVER['REMOTE_ADDR']??'cli'),12,300))return false;
        $u=Db::one('SELECT * FROM users WHERE id=? AND enabled=1',[$id]);if(!$u||empty($u['totp_secret'])||!hash_equals(self::proof($u),$_SESSION['auth_proof']??''))return false;
        $code=trim($code);$step=Totp::matchStep(Secrets::decrypt($u['totp_secret']),$code,null,(int)$u['totp_last_step']);
        $ok=$step!==null ? Db::run('UPDATE users SET totp_last_step=? WHERE id=? AND totp_last_step<?',[$step,$id,$step])===1 : self::consumeRecovery($id,$code);
        if(!$ok){Audit::log($u['email'],'auth.second-factor','',[],'failed');return false;}
        session_regenerate_id(true);$_SESSION['uid']=$id;$_SESSION['reauth_at']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));unset($_SESSION['pending_uid'],$_SESSION['pending_at']);Audit::log($u['email'],'auth.login');return true;
    }
    public static function consumeRecovery(int $id,string $code): bool
    {
        $code=strtoupper(str_replace('-','',trim($code)));if(!preg_match('/\A(?:[A-F0-9]{12}|[A-F0-9]{32})\z/D',$code))return false;
        return Db::run('DELETE FROM recovery_codes WHERE user_id=? AND hash=?',[$id,hash('sha256',$code)])===1;
    }
    public static function reauthenticate(string $password,bool $force=false): void
    {
        $u=self::user();if(!$u) {self::require('viewer');return;}
        if(!$force&&($_SESSION['reauth_at']??0)>time()-300)return;
        if(!RateLimit::hit('reauth:'.$u['id'],10,300))throw new \InvalidArgumentException('確認が多いため、しばらく待ってから操作してください');
        $row=Db::one('SELECT password_hash FROM users WHERE id=?',[$u['id']]);
        if(!password_verify($password,$row['password_hash']))throw new \InvalidArgumentException('現在のパスワードを確認してください');$_SESSION['reauth_at']=time();
    }
    public static function userCanSite(int $id,int $serviceId,string $role='viewer'): bool
    {
        $u=Db::one('SELECT role,scope_all,enabled FROM users WHERE id=?',[$id]);if(!$u||!$u['enabled'])return false;
        if($u['role']==='owner')return true;if(Db::one("SELECT service_id FROM service_lifecycle WHERE service_id=? AND state<>'active'",[$serviceId]))return false;if((self::RANK[$u['role']]??0)<(self::RANK[$role]??99))return false;
        if($u['scope_all'])return true;$grant=Db::one('SELECT role FROM service_access WHERE user_id=? AND service_id=?',[$id,$serviceId]);return (self::RANK[$grant['role']??'']??0)>=(self::RANK[$role]??99);
    }
    public static function canSite(string $name,string $role='viewer'): bool
    {
        $u=self::user();if(!$u)return false;$s=Db::one('SELECT id FROM services WHERE name=?',[$name]);return $s&&self::userCanSite((int)$u['id'],(int)$s['id'],$role);
    }
    public static function requireSite(string $name,string $role='viewer'): void
    {
        if(self::user()===null){if(PHP_SAPI==='cli')return;self::require('viewer');}
        if(!self::canSite($name,$role)){View::error(403,'このサイトに対する操作権限がありません。管理者に確認してください。');exit;}
    }

    public static function logout(): void
    {
        Audit::log(self::actor(), 'auth.logout');
        $_SESSION = [];
        session_destroy();
    }

    public static function can(string $role): bool
    {
        $u = self::user();
        return $u !== null && (self::RANK[$u['role']] ?? 0) >= (self::RANK[$role] ?? 99);
    }

    public static function require(string $role): void
    {
        if (self::user() === null) {
            header('Location: /login');
            exit;
        }
        if (!self::can($role)) {
            View::error(403, 'この操作には「' . \Pjas\Support\Labels::role($role) . '」権限が必要です。管理者に確認してください。');
            exit;
        }
    }
}
