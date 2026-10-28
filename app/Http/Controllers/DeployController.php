<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentException;
use Pjas\Domain\Deployer;
use Pjas\Domain\ReleaseRepo;
use Pjas\Agent\AgentClient;
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\ValidationException;
use Pjas\Http\Auth;
use Pjas\Http\View;

final class DeployController
{
    public function form(): void
    {
        View::render('deploy', [
            'title'    => '新しいバージョンを公開',
            'services' => ServiceRepo::all(),
            'selected' => (string) ($_GET['service'] ?? ''),
        ]);
    }

    public function rollbackForm(): void
    {
        $services = ServiceRepo::all();
        $selected = (string) ($_GET['service'] ?? ($services[0]['name'] ?? ''));
        $site = null; $versions = []; $current = ''; $error = '';
        foreach ($services as $candidate) if ($candidate['name'] === $selected) $site = $candidate;
        if ($site !== null) {
            try {
                $available = AgentClient::call('deploy.releases',['service'=>Deployer::spec($site)],Auth::actor());
                $current = (string) ($available['current'] ?? '');
                foreach ($available['tags'] as $tag) {
                    $release = ReleaseRepo::find((int)$site['id'],$tag);
                    if ($tag !== $current && ($release['state'] ?? '') !== 'failed') $versions[] = ['tag'=>$tag,'label'=>$release['label'] ?? ''];
                }
            } catch (AgentException $e) { $error = $e->getMessage(); }
        } elseif ($selected !== '') $error = '選択されたサイトが見つかりません';
        View::render('rollback',['title'=>'以前のバージョンに戻す','services'=>$services,'selected'=>$selected,
            'versions'=>$versions,'current'=>$current,'error'=>$error]);
    }

    public function deploy(): void
    {
        $s     = ServiceRepo::findOrFail((string) ($_POST['service'] ?? ''));
        $actor = Auth::actor();
        $opts  = [
            'label'         => (string) ($_POST['label'] ?? ''),
            'auto_rollback' => isset($_POST['auto_rollback']),
            'maintenance'   => isset($_POST['maintenance']),
        ];

        try {
            $job = ($_POST['source'] ?? 'upload') === 'git'
                ? Deployer::fromGit($s, (string) ($_POST['git_ref'] ?? ''), $opts, $actor)
                : Deployer::fromUpload($s, $_FILES['archive'] ?? [], $opts, $actor);
        } catch (ValidationException|AgentException $e) {
            View::redirect('/deploy?service=' . urlencode($s['name']), $e->getMessage(), 'err');
        }

        View::redirect('/jobs/' . $job, '公開を開始しました。進み具合はこの画面で確認できます');
    }

    /** 既存ファイルの取り込み (導入時の移行用。新しいアーカイブは要らない) */
    public function adopt(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        try {
            $daemons = (array) ($_POST['daemons'] ?? []);
            if ($daemons && !Auth::can('owner')) throw new ValidationException(['常駐プログラムの移行には管理者権限が必要です']);
            $job = Deployer::adopt($s, Auth::actor(), $daemons);
        } catch (ValidationException|AgentException $e) {
            View::redirect('/services/' . $s['name'], $e->getMessage(), 'err');
        }
        View::redirect('/jobs/' . $job, '既存ファイルの取り込みを開始しました');
    }

    public function rollback(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        try {
            $job = Deployer::rollback($s, (string) ($_POST['tag'] ?? ''), Auth::actor(), isset($_POST['expected_current']) ? (string)$_POST['expected_current'] : null);
        } catch (ValidationException|AgentException $e) {
            View::redirect('/services/' . $s['name'] . '?tab=releases', $e->getMessage(), 'err');
        }
        View::redirect('/jobs/' . $job, '前のバージョンへの切り替えを開始しました');
    }
}
