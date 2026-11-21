<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Domain\{ServiceRepo,Deployer};
use Pjas\Http\{Auth,View};
use Pjas\Support\{Audit,Db};

final class ComponentController
{
    public const OPS=['components.webplan','components.webapply','components.plan','components.apply','components.release','components.publish','components.setup','components.action','components.rollback','components.backup','components.restoreplan','components.restore','components.resume','tls.plan','tls.apply'];
    public function index(): void
    {
        $selection=(string)($_GET['service']??'');
        if ($selection==='') View::redirect('/services');
        $site=ServiceRepo::findOrFail($selection); Auth::requireSite($site['name'],'owner');
        View::redirect('/services/'.rawurlencode($site['name']).'?tab=environment');
    }
    public static function panel(array $site): array
    {
        Auth::requireSite($site['name'],'owner');
        $state=[]; $error='';
        try {
            $state=AgentClient::call('components.status',['service'=>$site['name']],Auth::actor());
            $state['tls']=AgentClient::call('tls.status',['service'=>$site['name']],Auth::actor());
        } catch (AgentException $e) { $error=$e->getMessage(); }
        if (!empty($site['tls'])) {
            try {
                $state['tls']['certificate'] = AgentClient::call('ops.certificate', ['service'=>Deployer::spec($site)], Auth::actor());
            } catch (AgentException) {
                $state['tls']['certificate_unavailable'] = true;
            }
        }
        return ['site'=>$site,'state'=>$state,'error'=>$error,
            'review'=>$_SESSION['component_review'][$site['name']]??null];
    }
    public function action(array $args=[]): void
    {
        $site=ServiceRepo::findOrFail((string)($args['name']??$_POST['service']??'')); Auth::requireSite($site['name'],'owner');
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
            if (str_starts_with($op, 'components.')) {
                Db::run('INSERT INTO component_sites(service_id,phase,observed_at) VALUES (?,?,datetime(\'now\')) ON CONFLICT(service_id) DO UPDATE SET phase=excluded.phase,observed_at=excluded.observed_at',[$site['id'],$result['phase']??'registered']);
            }
            Audit::log(Auth::actor(),$op,$site['name'],['result'=>'ok']);
            View::redirect('/services/'.rawurlencode($site['name']).'?tab=environment',isset($result['review'])?'変更内容を確認してください':'操作が完了しました');
        } catch (\InvalidArgumentException|AgentException $e) {
            if ($op==='tls.apply') Audit::log(Auth::actor(), $op, $site['name'], [], 'failed');
            View::redirect('/services/'.rawurlencode($site['name']).'?tab=environment',$e->getMessage(),'err'); }
    }
}
