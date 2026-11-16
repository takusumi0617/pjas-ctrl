<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentClient;
use Pjas\Agent\AgentException;
use Pjas\Domain\ScheduledTaskRepo;
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\ValidationException;
use Pjas\Http\Auth;
use Pjas\Http\View;
use Pjas\Support\Audit;

final class ScheduledTaskController
{
    private function returnTo(string $site): string {return $site!==''&&($_POST['return_service']??'')===$site?'/services/'.rawurlencode($site).'?tab=processes':'/scheduled-tasks';}
    public function index(): void
    {
        View::render('scheduled_tasks', [
            'title' => '定期処理',
            'tasks' => ScheduledTaskRepo::all(),
            'services' => ServiceRepo::all(),
        ]);
    }

    public function create(): void
    {
        $return=$this->returnTo((string)($_POST['service']??''));
        try {
            $service = ServiceRepo::findOrFail((string) ($_POST['service'] ?? ''));
            $v = ScheduledTaskRepo::validate($_POST);
            $id = ScheduledTaskRepo::create((int) $service['id'], $v, Auth::actor());
            $task = ScheduledTaskRepo::find($id);
            AgentClient::call('cron.apply', ['task' => $task], Auth::actor());
            Audit::log(Auth::actor(), 'cron.create', $service['name'], ['id' => $id, 'schedule' => $v['schedule']]);
            View::redirect($return, '定期処理を登録しました');
        } catch (ValidationException $e) {
            View::redirect($return, implode(' / ', $e->errors), 'err');
        } catch (AgentException|\RuntimeException $e) {
            if (isset($id)) {
                ScheduledTaskRepo::delete($id);
            }
            View::redirect($return, $e->getMessage(), 'err');
        }
    }

    public function update(array $a): void
    {
        $id = (int) ($a['id'] ?? 0);
        $old = ScheduledTaskRepo::find($id);
        $return=$this->returnTo((string)($old['service']??''));
        if ($old === null) {
            View::redirect($return, '定期処理が見つかりません', 'err');
        }
        try {
            $v = ScheduledTaskRepo::validate($_POST);
            ScheduledTaskRepo::update($id, $v);
            AgentClient::call('cron.apply', ['task' => ScheduledTaskRepo::find($id)], Auth::actor());
            Audit::log(Auth::actor(), 'cron.update', $old['service'], ['id' => $id, 'schedule' => $v['schedule']]);
            View::redirect($return, '定期処理を更新しました');
        } catch (ValidationException $e) {
            View::redirect($return, implode(' / ', $e->errors), 'err');
        } catch (AgentException $e) {
            ScheduledTaskRepo::update($id, $old);
            View::redirect($return, $e->getMessage(), 'err');
        }
    }

    public function delete(array $a): void
    {
        $id = (int) ($a['id'] ?? 0);
        $task = ScheduledTaskRepo::find($id);
        $return=$this->returnTo((string)($task['service']??''));
        if ($task === null) {
            View::redirect($return, '定期処理が見つかりません', 'err');
        }
        try {
            AgentClient::call('cron.delete', ['id' => $id], Auth::actor());
        } catch (AgentException $e) {
            View::redirect($return, $e->getMessage(), 'err');
        }
        ScheduledTaskRepo::delete($id);
        Audit::log(Auth::actor(), 'cron.delete', $task['service'], ['id' => $id]);
        View::redirect($return, '定期処理を削除しました');
    }
}
