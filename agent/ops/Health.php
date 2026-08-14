<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;

/**
 * ヘルスチェック。DNS ではなく 127.0.0.1 に強制解決して、
 * 「このホストの Apache が返す応答」を確認する。
 */
final class Health
{
    public function __construct(private array $cfg)
    {
    }

    public function check(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        return $this->probe($spec);
    }

    public function probe(array $spec): array
    {
        $scheme = $spec['tls'] ? 'https' : 'http';
        $port   = $spec['tls'] ? 443 : 80;
        $url    = "{$scheme}://{$spec['domain']}{$spec['health_path']}";

        $r = Proc::run([
            '/usr/bin/curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}',
            '--max-time', '10', '--resolve', "{$spec['domain']}:{$port}:127.0.0.1",
            '-k', $url,
        ], null, 15);

        $status = (int) trim($r['out']);
        return [
            'ok'     => $status === $spec['health_status'],
            'status' => $status,
            'url'    => $url,
            'error'  => $r['code'] === 0 ? '' : trim($r['err']),
        ];
    }

    /** 成功するまで、または回数切れまで繰り返す */
    public function poll(array $spec, callable $log): bool
    {
        $tries = (int) $this->cfg['health_retries'];
        for ($i = 1; $i <= $tries; $i++) {
            $r = $this->probe($spec);
            $log(sprintf('health %s → %s (%d/%d)', $r['url'], $r['status'] ?: ($r['error'] ?: '接続不可'), $i, $tries));
            if ($r['ok']) {
                return true;
            }
            sleep((int) $this->cfg['health_wait']);
        }
        return false;
    }
}
