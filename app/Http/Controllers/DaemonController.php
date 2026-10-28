<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentClient;
use Pjas\Agent\AgentException;
use Pjas\Http\Auth;
use Pjas\Http\View;
use Pjas\Support\Audit;

final class DaemonController
{
    public function index(): void
    {
        $rows = [];
        $error = null;
        try {
            $rows = AgentClient::call('daemon.list', [], Auth::actor())['daemons'];
        } catch (AgentException $e) {
            $error = $e->getMessage();
        }
        View::render('daemons', ['title' => '常駐プログラム', 'daemons' => $rows, 'error' => $error]);
    }

    public function action(): void
    {
        $params = ['unit' => (string) ($_POST['unit'] ?? ''), 'action' => (string) ($_POST['action'] ?? '')];
        try {
            AgentClient::call('daemon.action', $params, Auth::actor());
        } catch (AgentException $e) {
            Audit::log(Auth::actor(), 'daemon.action', $params['unit'], $params, 'failed');
            View::redirect('/daemons', $e->getMessage(), 'err');
        }
        Audit::log(Auth::actor(), 'daemon.action', $params['unit'], $params);
        View::redirect('/daemons', '常駐プログラムの操作が完了しました');
    }
}
