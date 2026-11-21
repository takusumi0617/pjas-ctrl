<?php
declare(strict_types=1);

namespace Pjas\Agentd;

use Pjas\Agentd\Ops\Vhost;
use Pjas\Shared\PhpRuntime;

final class SiteDrift
{
    public static function inspect(array $cfg, array $spec, array $isolation = []): array
    {
        $name = Spec::name($spec['name']);
        $vhost = new Vhost($cfg, new Paths($cfg['www_root']));
        $path = $vhost->confPath($name);
        $enabled = rtrim($vhost->enabledDir(), '/').'/'.basename($path);
        $rows = [];
        if (is_file($path) || is_link($path) || file_exists($enabled) || is_link($enabled)
            || is_link((new Paths($cfg['www_root']))->current($name))) {
            try {
                $rows = ComponentDrift::inspect(['files' => [$path => hash('sha256', $vhost->render($spec))]]);
            } catch (OpException | \InvalidArgumentException | \JsonException $e) {
                $rows[] = ['path'=>$path, 'status'=>'unreadable', 'detail'=>'登録済み構成からApache設定を生成できません'];
            }
            $target = realpath($path);
            $link = is_link($enabled);
            $status = $link && $target !== false && realpath($enabled) === $target
                ? 'match' : ($link || file_exists($enabled) ? 'changed' : 'missing');
            $rows[] = ['path'=>$enabled, 'status'=>$status, 'detail'=>match ($status) {
                'match' => '有効化リンクの参照先が一致',
                'missing' => '有効化リンクがありません',
                default => '有効化リンクの参照先が一致しません',
            }];
        }

        $runtime = $isolation['runtime'] ?? 'static';
        if (($isolation['status'] ?? '') === 'ready' && $runtime !== 'static') {
            $pool = '/etc/php/'.PhpRuntime::version($runtime).'/fpm/pool.d/pjas-'.$name.'.conf';
            $body = SiteIsolation::pool($name, $runtime, $cfg['apache_user'], rtrim($cfg['www_root'], '/').'/'.$name);
            $rows = array_merge($rows, ComponentDrift::inspect(['files'=>[$pool=>hash('sha256', $body)]]));
        }
        return array_merge($rows, (new \Pjas\Agentd\Ops\Daemon($cfg))->configurationDrift($name));
    }
}
