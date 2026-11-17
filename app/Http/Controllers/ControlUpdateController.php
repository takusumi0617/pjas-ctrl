<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Http\{Auth,View};
use Pjas\Support\Audit;
final class ControlUpdateController
{
    public function action(): void
    {
        Auth::require('owner');$action=(string)($_POST['action']??'');
        try{
            if($action==='review'){
                $_SESSION['control_update_review']=AgentClient::call('control.review',[],Auth::actor());
                Audit::log(Auth::actor(),'control.review','ctrl');
                View::redirect('/#control-update','更新内容を取得しました。対象コミットと停止範囲を確認してください');
            }
            if($action!=='start')throw new \InvalidArgumentException('操作を選んでください');
            Auth::reauthenticate((string)($_POST['password']??''),true);
            $review=$_SESSION['control_update_review']??[];
            if(!hash_equals((string)($review['id']??''),(string)($_POST['review']??''))||empty($review['id']))throw new \InvalidArgumentException('更新内容を確認し直してください');
            $result=AgentClient::call('control.start',['review'=>$review['id'],'confirm'=>($_POST['confirm']??'')==='1'],Auth::actor());
            unset($_SESSION['control_update_review']);Audit::log(Auth::actor(),'control.start','ctrl',['id'=>$result['id'],'target'=>$review['target']]);
            View::redirect('/#control-update','更新サービスを起動しました。一時的に接続できなくなります。更新結果を再確認します');
        }catch(AgentException|\InvalidArgumentException $e){Audit::log(Auth::actor(),'control.'.$action,'ctrl',[],'failed');View::redirect('/#control-update',$e->getMessage(),'err');}
    }
    public function status(): void
    {
        Auth::require('owner');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try{echo json_encode(AgentClient::call('control.status',[],Auth::actor()),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
        catch(AgentException){http_response_code(503);echo '{"error":"更新サービスへの再接続を待っています"}';}
    }
}
