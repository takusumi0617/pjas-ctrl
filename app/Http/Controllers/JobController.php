<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Domain\JobRepo;
use Pjas\Http\View;
use Pjas\Support\Db;

final class JobController
{
    public function show(array $a): void
    {
        $job = JobRepo::find((int) $a['id']);
        if ($job === null) {
            View::error(404, '指定された作業の記録が見つかりません。ホームから作業の一覧を確認してください。');
            return;
        }
        if($job['service_id']){ $site=Db::one('SELECT name FROM services WHERE id=?',[$job['service_id']]);if($site)\Pjas\Http\Auth::requireSite($site['name']); }
        if(!$job['service_id']&&!\Pjas\Http\Auth::can('owner')){View::error(403);return;}
        $job=JobRepo::sync((int)$a['id'])??$job;
        $prepared=null;
        if($job['type']==='prepare'&&$job['state']==='succeeded'&&is_file(JobRepo::statusPath((int)$job['id'])))$prepared=json_decode(file_get_contents(JobRepo::statusPath((int)$job['id'])),true)['review']??null;
        $svc = $job['service_id']
            ? Db::one('SELECT name FROM services WHERE id = ?', [$job['service_id']])['name'] ?? ''
            : '';
        View::render('job', [
            'title'   => "作業 #{$job['id']}",
            'job'     => $job,
            'service' => $svc,
            'prepared'=> $prepared,
            'log'     => JobRepo::log((int) $job['id']),
        ]);
    }

    /** ジョブログの SSE ストリーム。agent が書き足すログを追従する。 */
    public function stream(array $a): void
    {
        $id = (int) $a['id'];
        $job=JobRepo::find($id);
        if($job&&$job['service_id']){ $site=Db::one('SELECT name FROM services WHERE id=?',[$job['service_id']]);if($site)\Pjas\Http\Auth::requireSite($site['name']); }
        if($job&&!$job['service_id']&&!\Pjas\Http\Auth::can('owner')){View::error(403);return;}
        if ($job === null) {
            http_response_code(404);
            return;
        }
        // SSE 中はセッションを掴んだままにしない (他のリクエストがブロックされる)
        session_write_close();

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $path   = JobRepo::logPath($id);
        $offset = max(0,(int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['offset'] ?? 0));
        $start  = time();

        while (time() - $start < 300) {
            if(\Pjas\Http\Auth::user()===null||($job['service_id']?!\Pjas\Http\Auth::canSite($site['name']):!\Pjas\Http\Auth::can('owner')))return;
            clearstatcache(false, $path);
            $size = is_file($path) ? (int) filesize($path) : 0;
            if($offset>$size){$offset=0;echo "event: reset\ndata: -\n\n";flush();}
            if ($size > $offset) {
                $fh = fopen($path, 'rb');
                fseek($fh, $offset);
                $chunk  = (string) fread($fh, min(65536,$size - $offset));
                fclose($fh);
                $offset += strlen($chunk);
                echo "id: {$offset}\nevent: chunk\ndata: ".json_encode($chunk,JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                flush();
            }

            $job = JobRepo::sync($id);
            clearstatcache(false,$path);$size=is_file($path)?(int)filesize($path):0;
            if ($offset >= $size && $job && in_array($job['state'], ['succeeded', 'failed'], true)) {
                echo "event: done\ndata: {$job['state']}\n\n";
                flush();
                return;
            }
            if (connection_aborted()) {
                return;
            }
            usleep(700_000);
        }
        echo "event: timeout\ndata: -\n\n";
    }
}
