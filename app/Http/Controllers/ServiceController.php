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
        View::render('services', ['title' => 'サイト一覧', 'services' => ServiceRepo::all()]);
    }

    public function newForm(): void
    {
        View::render('service_new', ['title' => 'サイト登録', 'errors' => [], 'in' => []]);
    }

    public function create(): void
    {
        try {
            $v = ServiceRepo::validate($_POST, true);
        } catch (ValidationException $e) {
            View::render('service_new', ['title' => 'サイト登録', 'errors' => $e->errors, 'in' => $_POST]);
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
            View::render('service_new', ['title' => 'サイト登録', 'errors' => [$e->getMessage()], 'in' => $_POST]);
            return;
        }

        ServiceRepo::create($v);
        Audit::log($actor, 'service.create', $v['name'], ['domain' => $v['domain']]);
        View::redirect('/services/' . $v['name'], "サイト {$v['name']} を登録しました。続けて「公開設定」タブで設定を反映してください。");
    }

    /** 一括登録: www_root を調べて、まだ登録されていないディレクトリを一覧にする */
    public function importForm(): void
    {
        $data = ['title' => 'サイトの一括登録', 'found' => [], 'root' => '', 'agentErr' => null, 'results' => []];
        try {
            $r = AgentClient::call('service.scan', [
                'known' => array_column(ServiceRepo::all(), 'name'),
            ], Auth::actor());
            $data['root']  = (string) ($r['root'] ?? '');
            $data['found'] = (array) ($r['services'] ?? []);
        } catch (AgentException $e) {
            $data['agentErr'] = $e->getMessage();
        }
        View::render('service_import', $data);
    }

    /**
     * 選ばれた行だけを登録する。1 件の失敗で全体を止めず、結果を行ごとに返す
     * (10 件登録して 1 件だけ名前が不正、というときに残り 9 件を無駄にしないため)。
     */
    public function import(): void
    {
        $actor    = Auth::actor();
        $selected = (array) ($_POST['pick'] ?? []);
        $rows     = (array) ($_POST['row'] ?? []);
        $adopt    = !empty($_POST['adopt']);
        $results  = [];

        foreach ($selected as $name) {
            $in = (array) ($rows[$name] ?? []);
            $in['name'] = (string) $name;

            try {
                $v = ServiceRepo::validate($in, true);
                AgentClient::call('service.create', [
                    'service' => [
                        'name'   => $v['name'],
                        'shared' => array_values(array_filter(explode(',', $v['shared_paths']))),
                    ],
                ], $actor);
                ServiceRepo::create($v);
                Audit::log($actor, 'service.create', $v['name'], ['domain' => $v['domain'], 'via' => 'import']);

                $note = '登録しました';
                if ($adopt) {
                    $s = ServiceRepo::findOrFail($v['name']);
                    $job = Deployer::adopt($s, $actor);
                    $note .= " / 既存ファイルの取り込みを開始しました (作業 #{$job})";
                }
                $results[] = ['name' => $name, 'ok' => true, 'note' => $note];
            } catch (ValidationException $e) {
                $results[] = ['name' => $name, 'ok' => false, 'note' => implode(' / ', $e->errors)];
            } catch (AgentException|\RuntimeException $e) {
                $results[] = ['name' => $name, 'ok' => false, 'note' => $e->getMessage()];
            }
        }

        $ok = count(array_filter($results, static fn(array $r): bool => $r['ok']));
        View::render('service_import', [
            'title'    => 'サイトの一括登録',
            'root'     => '',
            'found'    => [],
            'agentErr' => null,
            'results'  => $results,
            'summary'  => count($results) === 0
                ? '登録するサイトが選ばれていません'
                : "{$ok} / " . count($results) . ' 件を登録しました',
        ]);
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
            $data['rendered'] = VhostRenderer::render(
                $s,
                $data['wwwRoot'],
                (string) Config::get('tls_cert_file', VhostRenderer::DEFAULT_TLS_CERT_FILE),
                (string) Config::get('tls_key_file', VhostRenderer::DEFAULT_TLS_KEY_FILE),
            );
            try {
                $cur = AgentClient::call('apache.render', ['service' => Deployer::spec($s), 'migration_source' => (string) ($_GET['migration_source'] ?? '')], Auth::actor());
                foreach (['migration_sources', 'migration_source', 'migration_hash', 'inherited'] as $field) $data[$field] = $cur[$field] ?? null;
                $data['live']    = (string) ($cur['current'] ?? '');
                $data['managed'] = (bool) ($cur['managed'] ?? false);
                $data['conflicts'] = (array) ($cur['conflicts'] ?? []);
                $data['rendered'] = (string) ($cur['rendered'] ?? $data['rendered']); // agent 側が正
            } catch (AgentException $e) {
                $data['live'] = '';
                $data['managed'] = false;
                $data['conflicts'] = [];
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
            $msg = sprintf('%s%s の確認結果: %s', $s['domain'], $s['health_path'], !empty($r['ok']) ? '正常' : '確認が必要')
                . '（応答番号 ' . ($r['status'] ?? '?') . '）';
            View::redirect('/services/' . $s['name'], $msg, !empty($r['ok']) ? 'ok' : 'err');
        } catch (AgentException $e) {
            View::redirect('/services/' . $s['name'], $e->getMessage(), 'err');
        }
    }
}
