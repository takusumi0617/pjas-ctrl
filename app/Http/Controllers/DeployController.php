<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentException;
use Pjas\Domain\Deployer;
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\ValidationException;
use Pjas\Http\Auth;
use Pjas\Http\View;

final class DeployController
{
    public function form(): void
    {
        View::render('deploy', [
            'title'    => '新規デプロイ',
            'services' => ServiceRepo::all(),
            'selected' => (string) ($_GET['service'] ?? ''),
        ]);
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
            $job = Deployer::adopt($s, Auth::actor());
        } catch (ValidationException|AgentException $e) {
            View::redirect('/services/' . $s['name'], $e->getMessage(), 'err');
        }
        View::redirect('/jobs/' . $job, '既存ファイルの取り込みを開始しました');
    }

    public function rollback(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        try {
            $job = Deployer::rollback($s, (string) ($_POST['tag'] ?? ''), Auth::actor());
        } catch (ValidationException|AgentException $e) {
            View::redirect('/services/' . $s['name'] . '?tab=releases', $e->getMessage(), 'err');
        }
        View::redirect('/jobs/' . $job, '前のバージョンへの切り替えを開始しました');
    }
}
