<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentClient;
use Pjas\Agent\AgentException;
use Pjas\Domain\Deployer;
use Pjas\Domain\JobRepo;
use Pjas\Domain\ReleaseRepo;
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\ValidationException;
use Pjas\Domain\VhostRenderer;
use Pjas\Http\Auth;
use Pjas\Http\View;
use Pjas\Support\Audit;
use Pjas\Support\Config;

final class ServiceController
{
    public function index(): void
    {
        View::render('services', ['title' => 'サービス', 'services' => ServiceRepo::all()]);
    }

    public function newForm(): void
    {
        View::render('service_new', ['title' => 'サービス登録', 'errors' => [], 'in' => []]);
    }

    public function create(): void
    {
        try {
            $v = ServiceRepo::validate($_POST, true);
        } catch (ValidationException $e) {
            View::render('service_new', ['title' => 'サービス登録', 'errors' => $e->errors, 'in' => $_POST]);
            return;
        }

        $actor = Auth::actor();
        try {
            // ディレクトリ雛形 (releases/ shared/ current) は agent が root で作る
            AgentClient::call('service.create', [
                'service' => [
                    'name'   => $v['name'],
                    'shared' => array_values(array_filter(explode(',', $v['shared_paths']))),
                ],
            ], $actor);
        } catch (AgentException $e) {
            View::render('service_new', ['title' => 'サービス登録', 'errors' => [$e->getMessage()], 'in' => $_POST]);
            return;
        }

        ServiceRepo::create($v);
        Audit::log($actor, 'service.create', $v['name'], ['domain' => $v['domain']]);
        View::redirect('/services/' . $v['name'], "サイト {$v['name']} を登録しました。続けて「公開設定」タブで設定を反映してください。");
    }

    public function show(array $a): void
    {
        $s   = ServiceRepo::findOrFail($a['name']);
        $tab = $_GET['tab'] ?? 'overview';

        $jobs = JobRepo::recent(15, (int) $s['id']);
        foreach ($jobs as $i => $j) {
            if (!in_array($j['state'], ['succeeded', 'failed'], true)) {
                $jobs[$i] = (JobRepo::sync((int) $j['id']) ?? $j) + ['service' => $s['name']];
            }
        }

        $data = [
            'title'    => $s['name'],
            'service'  => $s,
            'tab'      => $tab,
            'releases' => ReleaseRepo::forService((int) $s['id']),
            'current'  => ReleaseRepo::current((int) $s['id']),
            'jobs'     => $jobs,
            'wwwRoot'  => (string) Config::get('www_root', '/var/www'),
            'agentErr' => null,
        ];

        if ($tab === 'vhost') {
            $data['rendered'] = VhostRenderer::render($s, $data['wwwRoot']);
            try {
                $cur = AgentClient::call('apache.render', ['service' => Deployer::spec($s)], Auth::actor());
                $data['live']    = (string) ($cur['current'] ?? '');
                $data['managed'] = (bool) ($cur['managed'] ?? false);
                $data['rendered'] = (string) ($cur['rendered'] ?? $data['rendered']); // agent 側が正
            } catch (AgentException $e) {
                $data['live'] = '';
                $data['managed'] = false;
                $data['agentErr'] = $e->getMessage();
            }
            $data['diff'] = VhostRenderer::diff($data['live'], $data['rendered']);
        }

        if ($tab === 'files') {
            $path = (string) ($_GET['path'] ?? '');
            try {
                $data['listing'] = AgentClient::call('fs.list', [
                    'service' => ['name' => $s['name']], 'path' => $path,
                ], Auth::actor());
            } catch (AgentException $e) {
                $data['listing'] = ['path' => $path, 'entries' => []];
                $data['agentErr'] = $e->getMessage();
            }
        }

        View::render('service', $data);
    }

    public function updateSettings(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        try {
            $v = ServiceRepo::validate($_POST + ['name' => $s['name']], false);
        } catch (ValidationException $e) {
            View::redirect('/services/' . $s['name'] . '?tab=settings', implode(' / ', $e->errors), 'err');
        }
        ServiceRepo::update($s['name'], $v);
        Audit::log(Auth::actor(), 'service.update', $s['name'], $v);
        View::redirect('/services/' . $s['name'] . '?tab=settings',
            '設定を保存しました。公開アドレスなどを変更した場合は「公開設定」タブで反映してください。');
    }

    public function health(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        try {
            $r = AgentClient::call('health.check', ['service' => Deployer::spec($s)], Auth::actor());
            ServiceRepo::setState((int) $s['id'], !empty($r['ok']) ? 'healthy' : 'unhealthy');
            $msg = sprintf('%s%s → HTTP %s', $s['domain'], $s['health_path'], $r['status'] ?? '?');
            View::redirect('/services/' . $s['name'], $msg, !empty($r['ok']) ? 'ok' : 'err');
        } catch (AgentException $e) {
            View::redirect('/services/' . $s['name'], $e->getMessage(), 'err');
        }
    }
}
