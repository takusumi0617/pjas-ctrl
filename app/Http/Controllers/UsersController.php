<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Http\{Auth,View};
use Pjas\Support\{Db,Audit};
use Pjas\Domain\ServiceRepo;
final class UsersController
{
    public function index(): void
    {
        $edit=isset($_GET['id'])?Db::one('SELECT id,email,role,scope_all,enabled FROM users WHERE id=?',[(int)$_GET['id']]):null;
        $grants=$edit?Db::all('SELECT service_id,role FROM service_access WHERE user_id=?',[$edit['id']]):[];
        View::render('users',['title'=>'利用者と操作できるサイト','users'=>Db::all('SELECT id,email,role,scope_all,enabled,totp_secret IS NOT NULL AS has_totp FROM users ORDER BY id'),'services'=>ServiceRepo::all(),'edit'=>$edit,'grants'=>array_column($grants,'role','service_id')]);
    }
    public function save(): void
    {
        try{
            Auth::reauthenticate((string)($_POST['confirm_password']??''));$id=(int)($_POST['id']??0);$email=strtolower(trim((string)($_POST['email']??'')));$role=(string)($_POST['role']??'viewer');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['viewer','operator','owner'],true))throw new \InvalidArgumentException('メールアドレスと権限を確認してください');
            $pw=(string)($_POST['new_password']??'');if(($id===0||$pw!=='')&&(strlen($pw)<12||strlen($pw)>1024))throw new \InvalidArgumentException('新しいパスワードは12文字以上にしてください');
            $enabled=!empty($_POST['enabled']);$all=$role==='owner'||!empty($_POST['scope_all']);$actor=Auth::user();
            if($id===$actor['id']&&!$enabled)throw new \InvalidArgumentException('操作中の自分のアカウントは停止できません');
            $grants=[];foreach((array)($_POST['sites']??[]) as $siteId=>$grant){if($grant==='')continue;if(!in_array($grant,['viewer','operator'],true)||!Db::one('SELECT id FROM services WHERE id=?',[(int)$siteId]))throw new \InvalidArgumentException('対象サイトを確認してください');$grants[(int)$siteId]=$grant;}
            if(!$all&&!$grants)throw new \InvalidArgumentException('操作できるサイトを1つ以上選んでください');
            Db::conn()->exec('BEGIN IMMEDIATE');
            try{
                $old=$id?Db::one('SELECT * FROM users WHERE id=?',[$id]):null;if($id&&!$old)throw new \InvalidArgumentException('利用者が見つかりません');
                if($old&&$old['role']==='owner'&&$old['enabled']&&(!$enabled||$role!=='owner')&&(int)Db::one("SELECT COUNT(*) n FROM users WHERE role='owner' AND enabled=1")['n']<=1)throw new \InvalidArgumentException('少なくとも1人の有効な管理者を残してください');
                if($id){Db::run('UPDATE users SET email=?,role=?,scope_all=?,enabled=?,auth_version=auth_version+1 WHERE id=?',[$email,$role,(int)$all,(int)$enabled,$id]);if($pw!=='')Db::run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$id]);}
                else $id=Db::insert('INSERT INTO users(email,password_hash,role,scope_all,enabled) VALUES (?,?,?,?,?)',[$email,password_hash($pw,PASSWORD_DEFAULT),$role,(int)$all,(int)$enabled]);
                Db::run('DELETE FROM service_access WHERE user_id=?',[$id]);foreach($grants as $siteId=>$grant)Db::run('INSERT INTO service_access(user_id,service_id,role) VALUES (?,?,?)',[$id,$siteId,$grant]);Db::conn()->exec('COMMIT');
            }catch(\Throwable $e){Db::conn()->exec('ROLLBACK');throw $e;}
            if($id===(int)$actor['id'])Auth::refreshProof($id);
            Audit::log($actor['email'],'user.save',(string)$id,['role'=>$role,'enabled'=>$enabled,'scope_all'=>$all]);View::redirect('/users','利用者とサイトの権限を保存しました');
        }catch(\InvalidArgumentException|\PDOException $e){View::redirect('/users', $e instanceof \PDOException?'保存できませんでした。同じメールアドレスが登録されていないか確認してください':$e->getMessage(),'err');}
    }
}
