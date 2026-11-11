<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Domain\{ServiceRepo,PjasManifest};
use Pjas\Http\{Auth,View};
use Pjas\Support\{Audit,Db};

final class ComponentController
{
    public const OPS=['components.webplan','components.webapply','components.plan','components.apply','components.release','components.publish','components.setup','components.action','components.rollback','components.backup','components.restoreplan','components.restore','components.resume','tls.plan','tls.apply'];
    public function index(): void
    {
        $sites=ServiceRepo::all(); $site=null; $state=[]; $error=''; $selection=(string)($_GET['service']??'');
        if ($selection!=='') {
            $site=ServiceRepo::findOrFail($selection); Auth::requireSite($selection,'owner');
            try { $state=AgentClient::call('components.status',['service'=>$selection],Auth::actor()); $state['tls']=AgentClient::call('tls.status',['service'=>$selection],Auth::actor()); }
            catch (AgentException $e) { $error=$e->getMessage(); }
        }
        $review=$_SESSION['component_review'][$selection]??null;
        View::render('components',['title'=>'隔離コンポーネント','sites'=>$sites,'site'=>$site,'state'=>$state,'review'=>$review,'error'=>$error]);
    }
    public function action(): void
    {
        $site=ServiceRepo::findOrFail((string)($_POST['service']??'')); Auth::requireSite($site['name'],'owner');
        try {
            $op=(string)($_POST['operation']??'');
            if (!in_array($op,self::OPS,true)) throw new \InvalidArgumentException('操作を選んでください');
            $p=['service'=>$site['name']];
            foreach (['token','review','provider','backup','release','expected_current','action'] as $key) if (isset($_POST[$key])) $p[$key]=(string)$_POST[$key];
            $p['accept_history_loss']=($_POST['accept_history_loss']??'')==='1';
            if (!in_array($op,['components.plan','components.release','components.restoreplan','components.webplan','tls.plan'],true)) Auth::reauthenticate((string)($_POST['password']??''));
            $result=AgentClient::call($op,$p,Auth::actor());
            if (isset($result['review'])) $_SESSION['component_review'][$site['name']]=['operation'=>$op,'result'=>$result,'input'=>$p];
            else unset($_SESSION['component_review'][$site['name']]);
            Db::run('INSERT INTO component_sites(service_id,phase,observed_at) VALUES (?,?,datetime(\'now\')) ON CONFLICT(service_id) DO UPDATE SET phase=excluded.phase,observed_at=excluded.observed_at',[$site['id'],$result['phase']??'registered']);
            Audit::log(Auth::actor(),$op,$site['name'],['result'=>'ok']);
            View::redirect('/components?service='.rawurlencode($site['name']),isset($result['review'])?'変更内容を確認してください':'操作が完了しました');
        } catch (\InvalidArgumentException|AgentException $e) { View::redirect('/components?service='.rawurlencode($site['name']),$e->getMessage(),'err'); }
    }
}
