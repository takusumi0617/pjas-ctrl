<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;

/**
 * certbot による証明書の取得・更新。
 * --apache ではなく webroot 方式にして、Apache 設定は pjas-ctrl が一元管理する。
 */
final class Tls
{
    public function __construct(private array $cfg, private Paths $paths)
    {
    }

    public function issue(array $params): array
    {
        $spec    = Spec::service($params['service'] ?? null);
        $webroot = rtrim($this->paths->current($spec['name']) . '/' . $spec['docroot'], '/');
        if (!is_dir($webroot)) {
            throw new OpException("webroot がありません: {$webroot} (先にデプロイしてください)");
        }

        $r = Proc::run([
            $this->cfg['certbot'], 'certonly', '--webroot', '-w', $webroot,
            '-d', $spec['domain'], '--non-interactive', '--agree-tos',
            '-m', (string) $this->cfg['certbot_email'], '--keep-until-expiring',
        ], null, 300);

        if ($r['code'] !== 0) {
            throw new OpException('certbot: ' . trim($r['err'] ?: $r['out']));
        }
        return ['domain' => $spec['domain'], 'expires' => $this->expiry($spec['domain']), 'output' => trim($r['out'])];
    }

    public function renew(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $r = Proc::run([$this->cfg['certbot'], 'renew', '--cert-name', $spec['domain'], '--non-interactive'], null, 300);
        if ($r['code'] !== 0) {
            throw new OpException('certbot renew: ' . trim($r['err'] ?: $r['out']));
        }
        Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
        return ['domain' => $spec['domain'], 'expires' => $this->expiry($spec['domain'])];
    }

    private function expiry(string $domain): string
    {
        $pem = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        if (!is_file($pem)) {
            return '';
        }
        $r = Proc::run(['/usr/bin/openssl', 'x509', '-enddate', '-noout', '-in', $pem], null, 20);
        return trim(str_replace('notAfter=', '', $r['out']));
    }
}
