<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentClient;
use Pjas\Agent\AgentException;
use Pjas\Domain\Deployer;
use Pjas\Domain\GitHubRepository;
use Pjas\Domain\PjasManifest;
use Pjas\Domain\RegistrationSource;
use Pjas\Domain\ScheduledTaskRepo;
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
        try{$catalog=AgentClient::call('runtime.catalog',[],Auth::actor())['runtimes'];}catch(AgentException $e){$catalog=[];}
        View::render('service_new', ['title' => 'サイト登録', 'errors' => [], 'in' => [],'runtimeCatalog'=>$catalog]);
    }

    public function githubPreview(): void
    {
        $in = $_POST;
        try {
            $selection = RegistrationSource::read($in);
            if (!$selection['manifest']) throw new \InvalidArgumentException('GitHubを選び「.pjasから設定を読み込む」にチェックしてください');
            $r = AgentClient::call('github.inspect',['repo'=>$selection['repo'],'ref'=>$selection['ref'],'auth'=>$selection['auth']],Auth::actor());
            if ($r['found']) {
                $in = array_replace($in,$r['defaults'],['manifest_token'=>$r['token'],'manifest_json'=>$r['json']]);
                if ($in['domain'] === '' && preg_match('/^\s*ServerName\s+(\S+)/mi',$r['apache'],$host)) $in['domain'] = $host[1];
            }
            View::render('service_new',['title'=>'サイト登録','errors'=>[],'in'=>$in,'manifest_preview'=>$r]);
        } catch (AgentException|\InvalidArgumentException $e) {
            View::render('service_new',['title'=>'サイト登録','errors'=>[$e->getMessage()],'in'=>$in]);
        }
    }

    public function create(): void
    {
        try { $selection = RegistrationSource::read($_POST); }
        catch (\InvalidArgumentException $e) {
            View::render('service_new',['title'=>'サイト登録','errors'=>[$e->getMessage()],'in'=>$_POST]); return;
        }
        // チェックした場合だけ読み込み、取得元を切り替えたときは残ったtokenを無視する。
        if ($selection['manifest'] && $selection['token'] === '') {
            try {
                $r = AgentClient::call('github.inspect',['repo'=>(string)$_POST['github_repo'],'ref'=>(string)($_POST['github_ref'] ?? ''),'auth'=>$selection['auth']],Auth::actor());
                if ($r['found']) {
                    $in = array_replace($_POST,$r['defaults'],['manifest_token'=>$r['token'],'manifest_json'=>$r['json']]);
                    if ($in['domain'] === '' && preg_match('/^\s*ServerName\s+(\S+)/mi',$r['apache'],$host)) $in['domain']=$host[1];
                    View::render('service_new',['title'=>'設定の確認','errors'=>[],'in'=>$in,'manifest_preview'=>$r]);
                    return;
                }
            } catch (AgentException $e) {
                View::render('service_new',['title'=>'サイト登録','errors'=>[$e->getMessage()],'in'=>$_POST]);
                return;
            }
        }
        try {
            $v = ServiceRepo::validate($_POST, true);
            if($v['extra_conf']!==''||$v['allow_override']!=='None')throw new ValidationException(['新規の隔離サイトは標準Apache設定・AllowOverride Noneを使用してください']);
            $repo = $selection['repo'];
            $ref = $selection['ref'];
            $file = $_FILES['archive'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
            if ($selection['type'] === 'upload' && !in_array($file['error'] ?? -1,[UPLOAD_ERR_OK,UPLOAD_ERR_NO_FILE],true)) throw new ValidationException(['圧縮ファイルを受け取れませんでした。ファイルサイズなどを確認してください']);
            $manifest = null;
            if ($selection['manifest'] && $selection['token'] !== '') {
                if (empty($_POST['manifest_confirm'])) throw new ValidationException(['.pjasとApache設定を確認してチェックしてください']);
                $manifest = PjasManifest::parse((string) ($_POST['manifest_json'] ?? ''));
            }
        } catch (ValidationException|\InvalidArgumentException $e) {
            View::render('service_new', ['title' => 'サイト登録', 'errors' => $e instanceof ValidationException ? $e->errors : [$e->getMessage()], 'in' => $_POST]);
            return;
        }

        $actor = Auth::actor();
        try {
            if (($manifest['version']??1)===2) {
                $review=AgentClient::call('components.plan',['service'=>$v['name'],'token'=>(string)$_POST['manifest_token']],$actor);
                $expected=PjasManifest::defaults($review['proposed']);
                foreach (['name','domain','runtime','docroot'] as $key) if ($v[$key]!==$expected[$key]) throw new AgentException('v2サイトの公開設定は承認済みマニフェストと一致させてください');
                ServiceRepo::create($v);
                \Pjas\Support\Db::run('INSERT INTO component_sites(service_id) VALUES (?)',[ServiceRepo::findOrFail($v['name'])['id']]);
                Audit::log($actor,'components.register',$v['name'],['sha'=>$review['sha']]);
                View::redirect('/components?service='.rawurlencode($v['name']),'サイトを登録しました。設定と固定コミットを確認し、適用・公開・初期化を順に実行してください');
            }
            if ($manifest !== null) AgentClient::call('github.validate', ['service'=>Deployer::spec($v),
                'token'=>(string)$_POST['manifest_token'],'json'=>(string)$_POST['manifest_json'],'repo'=>$repo,'ref'=>$ref,'auth'=>$selection['auth']],$actor);
            // ディレクトリ雛形 (releases/ shared/ current) は agent が root で作る
            AgentClient::call('service.create', [
                'github_repo' => $repo,
                'github_auth' => $selection['auth'],
                'service' => [
                    'runtime' => $v['runtime'],
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
        if ($repo !== '') {
            try {
                if ($manifest !== null) {
                    $tasks = [];
                    foreach ($manifest['cron'] as $item) {
                        $values = ScheduledTaskRepo::validate(['name'=>$item['label'] ?? $item['name'],
                            'schedule'=>$item['schedule'], 'command'=>PjasManifest::command($item['command'],$manifest,rtrim(Config::get('www_root','/var/www'),'/') . '/' . $v['name']),
                            'enabled'=>$item['enabled'] ?? true]);
                        $site = ServiceRepo::findOrFail($v['name']);
                        $id = ScheduledTaskRepo::create((int)$site['id'],$values,$actor);
                        $tasks[] = ScheduledTaskRepo::find($id);
                    }
                    AgentClient::call('github.configure',['service'=>Deployer::spec(ServiceRepo::findOrFail($v['name'])),
                        'token'=>(string)$_POST['manifest_token'],'json'=>(string)$_POST['manifest_json'],'tasks'=>$tasks],$actor);
                    $configured = true;
                }
                $job = Deployer::fromGit(ServiceRepo::findOrFail($v['name']), $ref,
                    ['label' => 'GitHubから初回公開', 'apply_vhost' => true, 'auto_rollback' => true], $actor);
            } catch (AgentException|ValidationException $e) {
                if (isset($tasks) && empty($configured)) foreach ($tasks as $task) ScheduledTaskRepo::delete((int)$task['id']);
                View::redirect('/services/' . $v['name'], 'サイトは登録済みですが、GitHubからの取得を開始できませんでした: ' . $e->getMessage(), 'err');
            }
            View::redirect('/jobs/' . $job, 'サイトを登録し、GitHubからの取得と初回公開を開始しました');
        }
        if ($selection['type'] === 'upload' && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $job = Deployer::fromUpload(ServiceRepo::findOrFail($v['name']),$file,
                    ['label'=>'圧縮ファイルから初回公開','apply_vhost'=>true,'auto_rollback'=>true],$actor);
            } catch (ValidationException|AgentException $e) {
                View::redirect('/services/' . $v['name'],'サイトは登録済みですが初回公開に失敗しました: ' . $e->getMessage(),'err');
            }
            View::redirect('/jobs/' . $job,'サイトを登録し、圧縮ファイルから初回公開を開始しました');
        }
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
            $data['daemon_candidates'] = AgentClient::call('daemon.candidates', [], Auth::actor())['units'];
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
                if (!$adopt && !empty($in['daemons'])) throw new ValidationException(['常駐プログラムも移行する場合は既存ファイルの取り込みをオンにしてください']);
                $v = ServiceRepo::validate($in, true);
                AgentClient::call('service.create', [
                    'service' => [
                        'runtime' => $v['runtime'],
                        'name'   => $v['name'],
                        'shared' => array_values(array_filter(explode(',', $v['shared_paths']))),
                    ],
                ], $actor);
                ServiceRepo::create($v);
                Audit::log($actor, 'service.create', $v['name'], ['domain' => $v['domain'], 'via' => 'import']);

                $note = '登録しました';
                if ($adopt) {
                    $s = ServiceRepo::findOrFail($v['name']);
                    $job = Deployer::adopt($s, $actor, (array) ($in['daemons'] ?? []));
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

        try { $data['pause'] = AgentClient::call('apache.pause.status', ['service' => Deployer::spec($s)], Auth::actor()); }
        catch (AgentException $e) { $data['pause'] = null; $data['pauseErr'] = $e->getMessage(); }

        if ($tab === 'overview' && $data['current'] === null && Auth::can('owner')) {
            try { $data['daemon_candidates'] = AgentClient::call('daemon.candidates', [], Auth::actor())['units']; }
            catch (AgentException $e) { $data['daemon_candidates'] = []; }
        }

        if ($tab === 'vhost') {
            $data['update_mode'] = 'auto';
            $data['rendered'] = VhostRenderer::render(
                $s,
                $data['wwwRoot'],
                (string) Config::get('tls_cert_file', VhostRenderer::DEFAULT_TLS_CERT_FILE),
                (string) Config::get('tls_key_file', VhostRenderer::DEFAULT_TLS_KEY_FILE),
            );
            try {
                $cur = AgentClient::call('apache.render', ['service' => Deployer::spec($s), 'migration_source' => (string) ($_GET['migration_source'] ?? ''), 'update_mode'=>$data['update_mode']], Auth::actor());
                foreach (['migration_sources', 'migration_source', 'migration_hash', 'inherited', 'current_hash', 'errors_available', 'preview_error', 'resolved_mode', 'can_apply', 'history', 'history_warning'] as $field) $data[$field] = $cur[$field] ?? null;
                $data['live']    = (string) ($cur['current'] ?? '');
                $data['managed'] = (bool) ($cur['managed'] ?? false);
                $data['conflicts'] = (array) ($cur['conflicts'] ?? []);
                $data['git_diff'] = $cur['git_diff'] ?? null;
                $data['rendered'] = (string) ($cur['rendered'] ?? $data['rendered']); // agent 側が正
            } catch (AgentException $e) {
                $data['live'] = '';
                $data['managed'] = false;
                $data['conflicts'] = [];
                $data['agentErr'] = $e->getMessage();
            }
            $data['diff'] = $data['git_diff'] ?? VhostRenderer::diff($data['live'], $data['rendered']);
            $data['error_coverage'] = [
                'current' => \Pjas\Shared\SiteErrors::coverage($data['live']),
                'planned' => \Pjas\Shared\SiteErrors::coverage($data['rendered']),
            ];
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

        try {$data['runtimeCatalog']=AgentClient::call('runtime.catalog',[],Auth::actor())['runtimes'];$data['isolation']=AgentClient::call('isolation.status',['service'=>Deployer::spec($s)],Auth::actor());}
        catch(AgentException $e){$data['isolationError']=$e->getMessage();}
        $data['componentSite']=(bool)\Pjas\Support\Db::one('SELECT service_id FROM component_sites WHERE service_id=?',[$s['id']]);
        if ($tab==='environment' && $data['componentSite'] && Auth::can('owner')) {
            $data['componentPanel']=ComponentController::panel($s);
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
        try {
            $isolation=AgentClient::call('isolation.status',['service'=>Deployer::spec($s)],Auth::actor());
            if(!empty($isolation['isolated'])&&($v['runtime']!==$s['runtime']||$v['extra_conf']!==''||$v['allow_override']!=='None'))throw new AgentException('隔離サイトのPHP変更は「実行環境」タブで行ってください。追加Apache設定と.htaccessは使用できません');
        } catch(AgentException $e){View::redirect('/services/'.$s['name'].'?tab=settings',$e->getMessage(),'err');}
        ServiceRepo::update($s['name'], $v);
        Audit::log(Auth::actor(), 'service.update', $s['name'], $v);
        View::redirect('/services/' . $s['name'] . '?tab=settings',
            '設定を保存しました。公開アドレスなどを変更した場合は「公開設定」タブで反映してください。');
    }

    public function isolation(array $a): void
    {
        $s=ServiceRepo::findOrFail($a['name']);Auth::requireSite($s['name'],'owner');
        try {
            Auth::reauthenticate((string)($_POST['password']??''));
            $result=AgentClient::call('isolation.apply',['service'=>Deployer::spec($s),'runtime'=>(string)($_POST['runtime']??''),'confirm'=>($_POST['confirm']??'')==='1','standardize'=>($_POST['standardize']??'')==='1'],Auth::actor());
            $hooks=$result['runtime']==='static'?$s['hooks']:\Pjas\Shared\PhpRuntime::command($s['hooks'],$result['runtime']);
            \Pjas\Support\Db::run("UPDATE services SET runtime=?,hooks=?,extra_conf='',allow_override='None' WHERE id=?",[$result['runtime'],$hooks,$s['id']]);
            Audit::log(Auth::actor(),'isolation.apply',$s['name'],$result);
            View::redirect('/services/'.$s['name'].'?tab=environment','隔離・PHP移行を適用しました。停止中のまま動作を確認し、公開を再開してください');
        } catch(AgentException|\InvalidArgumentException $e) { View::redirect('/services/'.$s['name'].'?tab=environment',$e->getMessage(),'err'); }
    }

    public function health(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        try {
            $r = AgentClient::call('health.check', ['service' => Deployer::spec($s)], Auth::actor());
            ServiceRepo::setState((int) $s['id'], !empty($r['ok']) ? 'healthy' : 'unhealthy');
            $msg = (!empty($r['paused']) ? '一時停止ページの確認: ' : '') . sprintf('%s%s の確認結果: %s', $s['domain'], $s['health_path'], !empty($r['ok']) ? '正常' : '確認が必要')
                . '（応答番号 ' . ($r['status'] ?? '?') . '）';
            View::redirect('/services/' . $s['name'], $msg, !empty($r['ok']) ? 'ok' : 'err');
        } catch (AgentException $e) {
            View::redirect('/services/' . $s['name'], $e->getMessage(), 'err');
        }
    }
}
