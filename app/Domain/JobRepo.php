<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Support\Config;
use Pjas\Support\Db;

/**
 * ジョブは web が起票し、agent が実行する。
 * agent は状態を job_dir/<id>.json に、ログを job_dir/<id>.log に書く。
 * web は表示時に sync() でそれを DB に取り込む (web は root を持たないため、
 * 実行中の状態はファイル経由で受け取る)。
 */
final class JobRepo
{
    public static function create(int $serviceId, string $type, string $actor, string $detail = ''): int
    {
        return Db::insert(
            'INSERT INTO jobs (service_id, type, state, detail, actor) VALUES (?,?,?,?,?)',
            [$serviceId, $type, 'queued', $detail, $actor]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 20, ?int $serviceId = null): array
    {
        $sql = 'SELECT j.*, s.name AS service FROM jobs j LEFT JOIN services s ON s.id = j.service_id';
        $arg = [];
        if ($serviceId !== null) {
            $sql .= ' WHERE j.service_id = ?';
            $arg[] = $serviceId;
        }
        if(class_exists(\Pjas\Http\Auth::class)&&($user=\Pjas\Http\Auth::user())!==null&&$user['role']!=='owner'){
            $ids=array_column(ServiceRepo::all(),'id');$sql.=$serviceId===null?' WHERE ':' AND ';
            $sql.=$ids?'j.service_id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'1=0';$arg=array_merge($arg,$ids);
        }
        $sql .= ' ORDER BY j.id DESC LIMIT ' . max(1,min(200,$limit));
        return Db::all($sql, $arg);
    }

    public static function logPath(int $id): string
    {
        return rtrim((string) Config::get('job_dir'), '/') . "/{$id}.log";
    }

    public static function statusPath(int $id): string
    {
        return rtrim((string) Config::get('job_dir'), '/') . "/{$id}.json";
    }

    public static function log(int $id): string
    {
        $p = self::logPath($id);
        return is_readable($p) ? (string) file_get_contents($p) : '';
    }

    /** agent が書いた状態ファイルを DB に取り込む。 */
    public static function sync(int $id): ?array
    {
        $job = self::find($id);
        if ($job === null || in_array($job['state'], ['succeeded', 'failed'], true)) {
            return $job;
        }
        $p = self::statusPath($id);
        if (!is_readable($p)) {
            return $job;
        }
        $st = json_decode((string) file_get_contents($p), true);
        if (!is_array($st) || empty($st['state'])) {
            return $job;
        }

        $state  = (string) $st['state'];                  // running|succeeded|failed
        $detail = (string) ($st['detail'] ?? $job['detail']);
        Db::run(
            'UPDATE jobs SET state = ?, detail = ?, finished_at = ? WHERE id = ?',
            [$state, $detail, in_array($state, ['succeeded', 'failed'], true) ? ($st['finished_at'] ?? date('Y-m-d H:i:s')) : null, $id]
        );

        if(in_array($job['type'],['site-delete','site-restore'],true)&&$job['service_id']&&in_array($state,['succeeded','failed'],true)){
            $result=$st['result']??[];$next=$state==='succeeded'?($result['state']??'failed'):'failed';
            Db::run("UPDATE service_lifecycle SET state=?,expires=?,detail=?,updated_at=datetime('now') WHERE service_id=? AND job_id=?",[$next,(int)($result['expires']??0),$detail,$job['service_id'],$id]);
            if($state==='succeeded'){
                if($job['type']==='site-delete'){ $policy=SitePolicy::get((int)$job['service_id']);$policy['backup_enabled']=false;$policy['webhook_enabled']=false;SitePolicy::write((int)$job['service_id'],$policy); }
                if(($result['mode']??'')==='delete')Db::run('UPDATE scheduled_tasks SET enabled=0 WHERE service_id=?',[$job['service_id']]);
            }
        }

        // 初回デプロイで既存ファイルを引き継いだ場合、そのリリースも履歴に載せる
        // (切り戻し先として選べるようにするため。release より先に入れて順序を保つ)
        if (!empty($st['adopted']) && $job['service_id']) {
            ReleaseRepo::syncFromAgent((int) $job['service_id'], $st['adopted'], (string) $job['actor']);
        }
        if (!empty($st['release']) && $job['service_id']) {
            ReleaseRepo::syncFromAgent((int) $job['service_id'], $st['release'], (string) $job['actor']);
        }
        if($state==='succeeded'&&$job['type']==='data-restore'&&!empty($st['result']['databases'])&&$job['service_id']){
            $s=Db::one('SELECT shared_paths FROM services WHERE id=?',[$job['service_id']]);
            if($s&&!in_array('@databases',explode(',',$s['shared_paths']),true))Db::run('UPDATE services SET shared_paths=? WHERE id=?',[trim($s['shared_paths'].',@databases',','),$job['service_id']]);
        }
        if (!empty($st['health']) && $job['service_id']) {
            ServiceRepo::setState((int) $job['service_id'], $st['health'] === 'ok' ? 'healthy' : 'unhealthy');
        }
        return self::find($id);
    }
}
