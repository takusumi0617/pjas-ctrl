<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Http\{Auth,View};
use Pjas\Support\{Db,Secrets,Audit,Config};
use Pjas\Domain\Totp;
final class SecurityController
{
    public function index(): void
    {
        $u=Auth::user();$row=Db::one('SELECT totp_secret FROM users WHERE id=?',[$u['id']]);
        if(($_SESSION['totp_setup_at']??0)<time()-600)unset($_SESSION['totp_setup']);
        $secret=$_SESSION['totp_setup']??'';$codes=$_SESSION['new_recovery_codes']??[];unset($_SESSION['new_recovery_codes']);
        $uri=$secret===''?'':'otpauth://totp/'.rawurlencode('pjas-ctrl:'.$u['email']).'?secret='.$secret.'&issuer=pjas-ctrl&algorithm=SHA1&digits=6&period=30';
        View::render('security',['title'=>'ログインの保護','enabled'=>!empty($row['totp_secret']),'secret'=>$secret,'uri'=>$uri,'codes'=>$codes]);
    }
    private function codes(int $id): array
    {
        Db::run('DELETE FROM recovery_codes WHERE user_id=?',[$id]);$out=[];for($i=0;$i<10;$i++){$raw=strtoupper(bin2hex(random_bytes(6)));Db::run('INSERT INTO recovery_codes(user_id,hash) VALUES (?,?)',[$id,hash('sha256',$raw)]);$out[]=implode('-',str_split($raw,4));}return $out;
    }
    public function action(): void
    {
        $u=Auth::user();try{
            if(!\Pjas\Support\RateLimit::hit('security:'.$u['id'],12,300))throw new \InvalidArgumentException('確認が多いため、しばらく待ってから操作してください');
            Auth::reauthenticate((string)($_POST['password']??''));$action=(string)($_POST['action']??'');
            $row=Db::one('SELECT totp_secret FROM users WHERE id=?',[$u['id']]);
            if($action==='begin'){
                if(!empty($row['totp_secret']))throw new \InvalidArgumentException('二段階認証はすでに有効です');$_SESSION['totp_setup']=Totp::secret();$_SESSION['totp_setup_at']=time();View::redirect('/security');
            }
            if($action==='enable'){
                $secret=$_SESSION['totp_setup']??'';if($secret===''||($_SESSION['totp_setup_at']??0)<time()-600)throw new \InvalidArgumentException('設定の期限が切れました。最初からやり直してください');
                $step=Totp::matchStep($secret,trim((string)($_POST['code']??'')));if($step===null)throw new \InvalidArgumentException('認証アプリの6桁コードを確認してください');
                Db::conn()->beginTransaction();try{Db::run('UPDATE users SET totp_secret=?,totp_last_step=? WHERE id=?',[Secrets::encrypt($secret),$step,$u['id']]);$codes=$this->codes((int)$u['id']);Db::conn()->commit();}catch(\Throwable $e){Db::conn()->rollBack();throw $e;}
                unset($_SESSION['totp_setup']);$_SESSION['new_recovery_codes']=$codes;
            }elseif(in_array($action,['disable','recovery'],true)){
                if(empty($row['totp_secret']))throw new \InvalidArgumentException('先に二段階認証を有効にしてください');$code=trim((string)($_POST['code']??''));
                if(Totp::matchStep(Secrets::decrypt($row['totp_secret']),$code)===null&&!Auth::consumeRecovery((int)$u['id'],$code))throw new \InvalidArgumentException('認証コードか未使用の復旧コードを確認してください');
                if($action==='disable'){Db::run('UPDATE users SET totp_secret=NULL,totp_last_step=-1 WHERE id=?',[$u['id']]);Db::run('DELETE FROM recovery_codes WHERE user_id=?',[$u['id']]);}
                else $_SESSION['new_recovery_codes']=$this->codes((int)$u['id']);
            }else throw new \InvalidArgumentException('操作を選んでください');
            Audit::log(Auth::actor(),'security.'.$action);View::redirect('/security',$action==='disable'?'二段階認証を解除しました':'設定を保存しました。復旧コードは安全な場所に保管してください');
        }catch(\InvalidArgumentException $e){View::redirect('/security',$e->getMessage(),'err');}
    }
}
