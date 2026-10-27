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
        try {
            $service = ServiceRepo::findOrFail((string) ($_POST['service'] ?? ''));
            $v = ScheduledTaskRepo::validate($_POST);
            $id = ScheduledTaskRepo::create((int) $service['id'], $v, Auth::actor());
            $task = ScheduledTaskRepo::find($id);
            AgentClient::call('cron.apply', ['task' => $task], Auth::actor());
            Audit::log(Auth::actor(), 'cron.create', $service['name'], ['id' => $id, 'schedule' => $v['schedule']]);
            View::redirect('/scheduled-tasks', '定期処理を登録しました');
        } catch (ValidationException $e) {
            View::redirect('/scheduled-tasks', implode(' / ', $e->errors), 'err');
        } catch (AgentException|\RuntimeException $e) {
            if (isset($id)) {
                ScheduledTaskRepo::delete($id);
            }
            View::redirect('/scheduled-tasks', $e->getMessage(), 'err');
        }
    }

    public function update(array $a): void
    {
        $id = (int) ($a['id'] ?? 0);
        $old = ScheduledTaskRepo::find($id);
        if ($old === null) {
            View::redirect('/scheduled-tasks', '定期処理が見つかりません', 'err');
        }
        try {
            $v = ScheduledTaskRepo::validate($_POST);
            ScheduledTaskRepo::update($id, $v);
            AgentClient::call('cron.apply', ['task' => ScheduledTaskRepo::find($id)], Auth::actor());
            Audit::log(Auth::actor(), 'cron.update', $old['service'], ['id' => $id, 'schedule' => $v['schedule']]);
            View::redirect('/scheduled-tasks', '定期処理を更新しました');
        } catch (ValidationException $e) {
            View::redirect('/scheduled-tasks', implode(' / ', $e->errors), 'err');
        } catch (AgentException $e) {
            ScheduledTaskRepo::update($id, $old);
            View::redirect('/scheduled-tasks', $e->getMessage(), 'err');
        }
    }

    public function delete(array $a): void
    {
        $id = (int) ($a['id'] ?? 0);
        $task = ScheduledTaskRepo::find($id);
        if ($task === null) {
            View::redirect('/scheduled-tasks', '定期処理が見つかりません', 'err');
        }
        try {
            AgentClient::call('cron.delete', ['id' => $id], Auth::actor());
        } catch (AgentException $e) {
            View::redirect('/scheduled-tasks', $e->getMessage(), 'err');
        }
        ScheduledTaskRepo::delete($id);
        Audit::log(Auth::actor(), 'cron.delete', $task['service'], ['id' => $id]);
        View::redirect('/scheduled-tasks', '定期処理を削除しました');
    }
}
