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
        } catch (AgentException $e) { $error = $e->getMessage(); }
        View::render('daemons', ['title' => '常駐プログラム', 'daemons' => $rows, 'units' => $units,
            'services' => ServiceRepo::all(), 'error' => $error]);
    }
    public function save(): void { $this->perform('daemon.save'); }
    public function action(): void { $this->perform('daemon.action'); }

    private function perform(string $op): void
    {
        $params = [];
        foreach (['service', 'target_service', 'unit', 'original_unit', 'label', 'mode', 'command', 'action'] as $key) {
            $params[$key] = (string) ($_POST[$key] ?? '');
        }
        ServiceRepo::findOrFail($params['service']);
        if ($params['target_service'] !== '') ServiceRepo::findOrFail($params['target_service']);
        try { AgentClient::call($op, $params, Auth::actor()); }
        catch (AgentException $e) {
            Audit::log(Auth::actor(), $op, $params['service'], ['unit' => $params['unit'], 'action' => $params['action']], 'failed');
            View::redirect('/daemons', $e->getMessage(), 'err');
        }
        Audit::log(Auth::actor(), $op, $params['service'], ['unit' => $params['original_unit'] ?: $params['unit'], 'action' => $params['action']]);
        View::redirect('/daemons', $op === 'daemon.save' ? '保存しました。実行内容の変更は次の起動・再起動で有効になります。' : '操作が完了しました');
    }
}
