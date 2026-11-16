<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Http\{Auth,View};
use Pjas\Support\{Db,Audit};
use Pjas\Domain\{ServiceRepo,Deployer,SitePolicy,PublicationQueue,JobRepo};
final class OperationsController
{
    public function index(): void
    {
        View::redirect('/');
    }
    public function connections(): void
    {
        $settings=['github'=>['configured'=>false,'app_id'=>'','slug'=>'','install_url'=>''],'notifications'=>['channel'=>'none'],'public_url'=>'','preview_domain'=>'','preview_user_ready'=>false];$error='';
        try{$settings=AgentClient::call('ops.settings',[],Auth::actor());}catch(AgentException $e){$error=$e->getMessage();}
        View::render('connections',['title'=>'接続設定と初回準備','settings'=>$settings,'error'=>$error]);
    }
    public function saveConnection(): void
    {
        try{
            $action=(string)($_POST['action']??'');if(!in_array($action,['notify-test','github-test'],true))Auth::reauthenticate((string)($_POST['password']??''));
            if($action==='settings')AgentClient::call('ops.save',$_POST,Auth::actor());
            elseif($action==='github'){
                $in=$_POST;$file=$_FILES['private_key_file']??[];
                if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){if(($file['size']??0)>32768||!is_uploaded_file($file['tmp_name']))throw new \InvalidArgumentException('秘密鍵ファイルを確認してください');$in['private_key']=file_get_contents($file['tmp_name']);}
                AgentClient::call('github.app.save',$in,Auth::actor());
            }elseif($action==='notify-test'){ $r=AgentClient::call('ops.notify',['message'=>'pjas-ctrlの通知テストです。接続できました。'],Auth::actor());if(empty($r['sent']))throw new \InvalidArgumentException($r['reason']??'先に通知先を保存してください'); }
            elseif($action==='github-test')AgentClient::call('github.app.test',['repo'=>(string)($_POST['repo']??'')],Auth::actor());
            else throw new \InvalidArgumentException('操作を選んでください');
            Audit::log(Auth::actor(),'connections.'.$action);View::redirect('/connections',str_ends_with($action,'test')?'接続を確認できました':'接続設定を保存しました');
        }catch(\InvalidArgumentException|AgentException $e){View::redirect('/connections',$e->getMessage(),'err');}
    }
    public function githubStart(): void
    {
        try{
            Auth::reauthenticate((string)($_POST['password']??''));$s=AgentClient::call('ops.settings',[],Auth::actor());if(empty($s['public_url']))throw new \InvalidArgumentException('先に管理画面のURLを保存してください');
            $state=bin2hex(random_bytes(24));$_SESSION['github_manifest']=['state'=>$state,'time'=>time(),'user'=>Auth::user()['id']];
            $name=trim((string)($_POST['app_name']??''))?:'pjas-ctrl-'.bin2hex(random_bytes(4));if(!preg_match('/\A[a-zA-Z0-9 -]{3,80}\z/D',$name))throw new \InvalidArgumentException('Appの名前は英数字とハイフンで入力してください');
            $manifest=['name'=>$name,'url'=>$s['public_url'],'hook_attributes'=>['url'=>$s['public_url'].'/hooks/github','active'=>true],
                'redirect_url'=>$s['public_url'].'/connections/github/callback','public'=>false,'default_permissions'=>['contents'=>'read','metadata'=>'read'],'default_events'=>['push']];
            View::render('github_create',['title'=>'GitHub Appを作成する','manifest'=>json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'state'=>$state]);
        }catch(\InvalidArgumentException|AgentException $e){View::redirect('/connections',$e->getMessage(),'err');}
    }
    public function githubCallback(): void
    {
        try{
            $saved=$_SESSION['github_manifest']??[];unset($_SESSION['github_manifest']);
            if(empty($saved)||($saved['time']??0)<time()-600||($saved['user']??0)!==Auth::user()['id']||!hash_equals($saved['state'],(string)($_GET['state']??'')))throw new \InvalidArgumentException('GitHubとの接続確認の期限が切れました。最初からやり直してください');
            AgentClient::call('github.app.convert',['code'=>(string)($_GET['code']??'')],Auth::actor());Audit::log(Auth::actor(),'connections.github');View::redirect('/connections','GitHub Appを作成しました。続いて「リポジトリを許可する」で対象を選んでください');
        }catch(\InvalidArgumentException|AgentException $e){View::redirect('/connections',$e->getMessage(),'err');}
    }
    public function site(array $args): void
    {
        $site=ServiceRepo::findOrFail($args['name']);Auth::requireSite($site['name'],'operator');$section=(string)($_GET['section']??'backups');
        if(!in_array($section,['backups','logs','storage','certificate','automation','preview','history'],true))$section='backups';
        $data=['title'=>$site['name'].'の運用','site'=>$site,'section'=>$section,'result'=>[],'error'=>'','policy'=>SitePolicy::get((int)$site['id']),'queue'=>PublicationQueue::forSite((int)$site['id']),
            'credentials'=>$_SESSION['preview_credentials'][$site['name']]??null,'tasks'=>[],'daemons'=>[],'jobs'=>[],'preparations'=>[],'restore_preview'=>null,'connection'=>[]];
        unset($_SESSION['preview_credentials'][$site['name']]);
        $p=['service'=>Deployer::spec($site)];
        try{
            if($section==='backups')$data['result']=AgentClient::call('backup.list',$p,Auth::actor());
            if($section==='storage')$data['result']=AgentClient::call('ops.resources',$p+['details'=>true],Auth::actor());
            if($section==='certificate')$data['result']=AgentClient::call('ops.certificate',$p,Auth::actor());
            if($section==='automation')$data['connection']=AgentClient::call('github.connection',$p,Auth::actor());
            if($section==='preview'){
                $data['result']=AgentClient::call('preview.status',$p,Auth::actor());$data['preparations']=AgentClient::call('deploy.preparations',$p,Auth::actor())['preparations'];
            }
            if($section==='history'){
                $data['result']=AgentClient::call('apache.history',$p,Auth::actor());
                if(!empty($_GET['revision']))$data['restore_preview']=AgentClient::call('apache.restore',$p+['revision'=>(string)$_GET['revision'],'action'=>'preview'],Auth::actor());
            }
            if($section==='logs'){
                $data['tasks']=Db::all('SELECT id,name FROM scheduled_tasks WHERE service_id=?',[$site['id']]);$data['daemons']=AgentClient::call('daemon.list',['service'=>$site['name']],Auth::actor())['daemons'];$data['jobs']=JobRepo::recent(30,(int)$site['id']);
                if(($_GET['source']??'')==='jobs'){
                    $id=(int)($_GET['job_id']??($data['jobs'][0]['id']??0));$job=JobRepo::find($id);if(!$job||(int)$job['service_id']!==(int)$site['id'])throw new \InvalidArgumentException('このサイトの作業を選んでください');
                    $content=substr(JobRepo::log($id),-128*1024);$q=substr((string)($_GET['query']??''),0,150);if($q!=='')$content=implode("\n",array_filter(explode("\n",$content),static fn($l)=>stripos($l,$q)!==false));$data['result']=['content'=>$content];
                }else $data['result']=AgentClient::call('ops.logs',$p+['source'=>(string)($_GET['source']??'apache-error'),'query'=>(string)($_GET['query']??''),'unit'=>(string)($_GET['unit']??($data['daemons'][0]['unit']??'')),'task_id'=>(int)($_GET['task_id']??($data['tasks'][0]['id']??0)),'file'=>(string)($_GET['file']??'app.log')],Auth::actor());
            }
        }catch(AgentException|\InvalidArgumentException $e){$data['error']=$e->getMessage();}
        View::render('site_operations',$data);
    }
    public function action(array $args): void
    {
        $site=ServiceRepo::findOrFail($args['name']);Auth::requireSite($site['name'],'operator');$action=(string)($_POST['action']??'');$section=(string)($_POST['section']??'backups');$p=['service'=>Deployer::spec($site)];
        try{
            if(in_array($action,['backup','preview-publish'],true)){
                $kind=$action==='backup'?'backup':'preview';$job=JobRepo::create((int)$site['id'],$kind,Auth::actor(),$kind==='backup'?'共有データ・DBの保存':'試験用URLの作成');
                try{AgentClient::call('ops.run',$p+['kind'=>$kind,'job_id'=>$job,'token'=>(string)($_POST['token']??'')],Auth::actor());}
                catch(\Throwable $e){Db::run("UPDATE jobs SET state='failed',detail=?,finished_at=datetime('now') WHERE id=?",[$e->getMessage(),$job]);throw $e;}
                View::redirect('/jobs/'.$job,'作業を開始しました。本番の公開バージョンは変更しません');
            }
            if($action==='cancel'){
                $row=Db::one('SELECT requested_by FROM publication_queue WHERE id=? AND service_id=?',[(int)($_POST['id']??0),$site['id']]);
                if(!Auth::can('owner')&&($row['requested_by']??0)!==Auth::user()['id'])throw new \InvalidArgumentException('自分が作成した予約だけを取り消せます');PublicationQueue::cancel((int)$_POST['id'],(int)$site['id']);
            }elseif($action==='preview-credentials'){
                Auth::reauthenticate((string)($_POST['password']??''));$_SESSION['preview_credentials'][$site['name']]=AgentClient::call('preview.credentials',$p,Auth::actor());
            }else{
                Auth::require('owner');Auth::reauthenticate((string)($_POST['password']??''));
                if($action==='policy')SitePolicy::save((int)$site['id'],$_POST,(int)Auth::user()['id']);
                elseif($action==='connection')AgentClient::call('github.connection',$p+['action'=>'save','repo'=>(string)($_POST['repo']??''),'auth'=>(string)($_POST['auth']??'public')],Auth::actor());
                elseif($action==='preview-save')AgentClient::call('preview.save',$p+['environment'=>(string)($_POST['environment']??''),'isolated_database'=>!empty($_POST['isolated_database'])],Auth::actor());
                elseif($action==='preview-remove')AgentClient::call('preview.remove',$p,Auth::actor());
                elseif($action==='history-restore')AgentClient::call('apache.restore',$p+['revision'=>(string)($_POST['revision']??''),'current_hash'=>(string)($_POST['current_hash']??''),'action'=>'restore'],Auth::actor());
                elseif($action==='restore'){
                    if(empty($_POST['writers_stopped']))throw new \InvalidArgumentException('書き込みを止めたことを確認してください');$job=JobRepo::create((int)$site['id'],'data-restore',Auth::actor(),'バックアップから復元');
                    try{AgentClient::call('ops.run',$p+['kind'=>'data-restore','job_id'=>$job,'id'=>(string)($_POST['id']??''),'writers_stopped'=>true],Auth::actor());}
                    catch(\Throwable $e){Db::run("UPDATE jobs SET state='failed',detail=?,finished_at=datetime('now') WHERE id=?",[$e->getMessage(),$job]);throw $e;}View::redirect('/jobs/'.$job,'復元を開始しました。サイトの停止状態は維持します');
                }elseif($action==='download'){$this->download($site,(string)($_POST['id']??''));return;}
                elseif($action==='key'){
                    Auth::reauthenticate((string)($_POST['password']??''),true);$key=AgentClient::call('backup.key',[],Auth::actor())['key'];Audit::log(Auth::actor(),'backup.key.export');header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="pjas-backup-key.txt"');header('Cache-Control: no-store');echo $key."\n";return;
                }else throw new \InvalidArgumentException('操作を選んでください');
            }
            Audit::log(Auth::actor(),'operations.'.$action,$site['name']);View::redirect('/services/'.$site['name'].'/operations?section='.urlencode($section),'設定・操作を完了しました');
        }catch(AgentException|\InvalidArgumentException $e){View::redirect('/services/'.$site['name'].'/operations?section='.urlencode($section),$e->getMessage(),'err');}
    }
    private function download(array $site,string $id): void
    {
        $offset=0;$p=['service'=>Deployer::spec($site),'id'=>$id];$part=AgentClient::call('backup.download',$p,Auth::actor());
        Audit::log(Auth::actor(),'backup.download',$site['name'],['id'=>$id]);header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$site['name'].'-'.$id.'.pjas"');header('Cache-Control: no-store');header('Content-Length: '.$part['size']);session_write_close();set_time_limit(0);
        while(true){$chunk=base64_decode($part['data'],true);if($chunk===false)throw new \RuntimeException('バックアップを受信できません');echo $chunk;$offset+=strlen($chunk);if($part['eof'])break;$part=AgentClient::call('backup.download',$p+['offset'=>$offset],Auth::actor());}
    }
}
