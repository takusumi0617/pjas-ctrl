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
use Pjas\Support\Time;
use Pjas\Domain\PublicationQueue;
use Pjas\Http\View;

final class DeployController
{
    public function form(): void
    {
        View::render('deploy', [
            'title'    => '新しいバージョンを公開',
            'services' => array_values(array_filter(ServiceRepo::all(),static fn($s)=>Auth::canSite($s['name'],'operator'))),
            'selected' => (string) ($_GET['service'] ?? ''),
        ]);
    }

    public function rollbackForm(): void
    {
        $services = array_values(array_filter(ServiceRepo::all(),static fn($s)=>Auth::canSite($s['name'],'operator')));
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

    public function review(): void
    {
        $site=ServiceRepo::findOrFail((string)($_POST['service']??''));Auth::requireSite($site['name'],'operator');
        try{
            $source=($_POST['source']??'upload')==='git'?['type'=>'git','ref'=>\Pjas\Domain\GitHubRepository::ref((string)($_POST['git_ref']??''))]:Deployer::stageUpload($_FILES['archive']??[]);
            $job=\Pjas\Domain\JobRepo::create((int)$site['id'],'prepare',Auth::actor(),'公開前の確認');
            try{AgentClient::call('deploy.prepare',['job_id'=>$job,'service'=>Deployer::spec($site),'source'=>$source,'options'=>['label'=>(string)($_POST['label']??''),'auto_rollback'=>isset($_POST['auto_rollback']),'maintenance'=>isset($_POST['maintenance'])]],Auth::actor());}
            catch(\Throwable $e){\Pjas\Support\Db::run("UPDATE jobs SET state='failed',detail=?,finished_at=datetime('now') WHERE id=?",[$e->getMessage(),$job]);throw $e;}
            View::redirect('/jobs/'.$job,'ファイルの取得と変更点の確認を始めました。まだ本番は変更しません');
        }catch(\InvalidArgumentException|ValidationException|AgentException $e){View::redirect('/deploy?service='.urlencode($site['name']),$e->getMessage(),'err');}
    }
    public function showReview(): void
    {
        $site=ServiceRepo::findOrFail((string)($_GET['service']??''));Auth::requireSite($site['name'],'operator');
        try{$plan=AgentClient::call('deploy.review',['service'=>Deployer::spec($site),'token'=>(string)($_GET['token']??'')],Auth::actor());
            View::render('deploy_review',['title'=>'公開前の確認','service'=>$site,'plan'=>$plan,'options'=>$plan['options']??['label'=>'','auto_rollback'=>true,'maintenance'=>false]]);
        }catch(AgentException $e){View::redirect('/deploy?service='.urlencode($site['name']),$e->getMessage(),'err');}
    }

    public function reviewFile(array $args): void
    {
        $site=ServiceRepo::findOrFail($args['name']);Auth::requireSite($site['name'],'operator');
        try{$r=AgentClient::call('deploy.diff',['service'=>Deployer::spec($site),'token'=>(string)($_GET['token']??''),'path'=>(string)($_GET['path']??'')],Auth::actor());
            View::render('file_diff',['title'=>'ファイルの変更点','site'=>$site,'result'=>$r,'path'=>(string)($_GET['path']??'')]);
        }catch(AgentException $e){View::redirect('/deploy?service='.urlencode($site['name']),$e->getMessage(),'err');}
    }
    public function confirm(): void
    {
        $site=ServiceRepo::findOrFail((string)($_POST['service']??''));Auth::requireSite($site['name'],'operator');
        try{
            $token=(string)($_POST['token']??'');$plan=AgentClient::call('deploy.review',['service'=>Deployer::spec($site),'token'=>$token],Auth::actor());
            $opts=['label'=>(string)($_POST['label']??''),'auto_rollback'=>!empty($_POST['auto_rollback']),'maintenance'=>!empty($_POST['maintenance'])];
            if(($_POST['publication_mode']??'now')==='scheduled'){
                $at=Time::reservation((string)($_POST['publish_at']??''));PublicationQueue::reserve($site,['type'=>'prepared','token'=>$token,'options'=>$opts],$at,(int)Auth::user()['id'],Auth::actor());
                View::redirect('/services/'.$site['name'].'/operations?section=automation','公開日時を予約しました。確認したファイルを指定日時に公開します');
            }
            $job=Deployer::fromPrepared($site,$token,$opts,Auth::actor());View::redirect('/jobs/'.$job,'確認したバージョンの公開を開始しました');
        }catch(\InvalidArgumentException|ValidationException|AgentException|\PDOException $e){View::redirect('/deploy?service='.urlencode($site['name']),$e instanceof \PDOException?'同じ内容の予約がすでにあります':$e->getMessage(),'err');}
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
