<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentClient;
use Pjas\Agent\AgentException;
use Pjas\Domain\ServiceRepo;
use Pjas\Http\Auth;
use Pjas\Http\View;
use Pjas\Support\Audit;

final class DaemonController
{
    public function index(): void
    {
        $rows = $units = [];
        $error = null;
        try {
            $rows = AgentClient::call('daemon.list', [], Auth::actor())['daemons'];
            $units = AgentClient::call('daemon.candidates', [], Auth::actor())['units'];
        } catch (AgentException|\InvalidArgumentException $e) { $error = $e->getMessage(); }
        View::render('daemons', ['title' => '常駐プログラム', 'daemons' => $rows, 'units' => $units,
            'services' => ServiceRepo::all(), 'error' => $error]);
    }
    public static function panel(array $site): array
    {
        Auth::requireSite($site['name'],'owner');$rows=$units=[];$error=null;
        try {
            $rows=AgentClient::call('daemon.list',['service'=>$site['name']],Auth::actor())['daemons'];
            $units=AgentClient::call('daemon.candidates',[],Auth::actor())['units'];
        } catch(AgentException $e){$error=$e->getMessage();}
        return ['services'=>[$site],'daemons'=>$rows,'units'=>$units,'error'=>$error,'embedded'=>true,'returnService'=>$site['name']];
    }
    public function save(): void { $this->perform('daemon.save'); }
    public function action(): void { $this->perform('daemon.action'); }

    private function perform(string $op): void
    {
        $params = [];
        foreach (['service', 'target_service', 'unit', 'original_unit', 'label', 'mode', 'command', 'exec_start', 'edit_execution', 'working_subdir', 'action'] as $key) {
            $params[$key] = (string) ($_POST[$key] ?? '');
        }
        $site=ServiceRepo::findOrFail($params['service']);Auth::requireSite($site['name'],'owner');
        $return=($_POST['return_service']??'')===$site['name']?'/services/'.rawurlencode($site['name']).'?tab=processes':'/daemons';
        if ($params['target_service'] !== '') ServiceRepo::findOrFail($params['target_service']);
        try {
            if($op==='daemon.action' && $params['action']==='detach') {
                Auth::reauthenticate((string)($_POST['password']??''));
                if(($_POST['confirm']??'')!=='1')throw new AgentException('関連付け解除の影響を確認してください');
            }
            AgentClient::call($op, $params, Auth::actor()); }
        catch (AgentException|\InvalidArgumentException $e) {
            Audit::log(Auth::actor(), $op, $params['service'], ['unit' => $params['unit'], 'action' => $params['action']], 'failed');
            View::redirect($return, $e->getMessage(), 'err');
        }
        Audit::log(Auth::actor(), $op, $params['service'], ['unit' => $params['original_unit'] ?: $params['unit'], 'action' => $params['action']]);
        View::redirect($return, $op === 'daemon.save' ? '保存しました。実行内容の変更は次の起動・再起動で有効になります。' : '操作が完了しました');
    }
}
