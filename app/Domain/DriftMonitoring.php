<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Support\Db;

final class DriftMonitoring
{
    public static function compare(array $previous, array $rows): array
    {
        $failures = $previous;
        $added = $resolved = [];
        foreach ($rows as $row) {
            $path = $row['path'];
            $status = $row['status'];
            if ($status === 'match') {
                if (isset($failures[$path])) $resolved[] = $path;
                unset($failures[$path]);
            } elseif ($status !== 'skip') {
                if (($failures[$path] ?? null) !== $status) $added[] = $path;
                $failures[$path] = $status;
            }
        }
        return ['failures'=>$failures,'added'=>$added,'resolved'=>$resolved];
    }

    private static function key(int $id): string
    {
        return 'config.drift.'.$id;
    }

    public static function status(int $id): array
    {
        $record = Db::one('SELECT value FROM app_state WHERE name=?',[self::key($id)]);
        return $record ? (json_decode($record['value'],true) ?: []) : [];
    }

    public static function tick(array $site, bool $notify, int $now): void
    {
        $id = (int) $site['id'];
        $state = self::status($id);
        if (($state['attempted_at'] ?? 0) > $now-3600) return;
        $state['attempted_at'] = $now;
        $response = [];
        try {
            $response = AgentClient::call('ops.drift', ['site'=>$site['name'],'spec'=>array_intersect_key($site, array_flip([
                'name','domain','runtime','docroot','allow_override','extra_conf','tls',
            ]))], 'monitor');
        } catch (AgentException $e) {
            $response['skip'] = '更新・復旧中、または監視エージェントの応答を確認できません';
        }
        $state['skip'] = $response['skip'] ?? null;
        $change = ['added'=>[], 'resolved'=>[]];
        if ($state['skip'] === null) {
            $state['rows'] = $response['rows'] ?? [];
            $state['checked_at'] = $now;
            $change = self::compare($state['failures'] ?? [], $state['rows']);
            $state['failures'] = $change['failures'];
        }
        Db::run('INSERT INTO app_state(name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value',
            [self::key($id),json_encode($state,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)]);
        if ($change['added']) self::alert($site,$change['added'],false,$notify);
        if ($change['resolved']) self::alert($site,$change['resolved'],true,$notify);
    }

    private static function alert(array $site, array $paths, bool $resolved, bool $notify): void
    {
        $files = implode('、',array_map('basename',array_slice($paths,0,8)));
        $remaining = count($paths)-8;
        if ($remaining > 0) $files .= ' ほか '.$remaining.'件';
        Alerting::event((int)$site['id'],'config.drift',$site['name'].' の構成'.($resolved?'が復旧':'に差分').': '.$files,$resolved,$notify);
    }
}
