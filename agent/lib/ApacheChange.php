<?php
declare(strict_types=1);

namespace Pjas\Agentd;

use Pjas\Agentd\Ops\{Components, Vhost};

/** Adapter for reviewed, existing standard VirtualHosts. */
final class ApacheChange
{
    private ControlStore $store;
    private ApacheRecovery $recovery;

    public function __construct(private array $cfg, private \Closure $database)
    {
        $this->store = new ControlStore($cfg);
        $this->recovery = new ApacheRecovery($this->store);
    }

    public function plan(string $site): array
    {
        $site = Spec::name($site);
        [$vhost, $params] = $this->target($site);
        $view = $vhost->preview($params);
        if (!$view['can_apply'] || $view['conflicts']) throw new OpException('競合のない標準公開設定だけを計画できます');
        $id = bin2hex(random_bytes(12));
        $plan = ['id'=>$id, 'site'=>$site, 'review'=>$view['change_review'], 'phase'=>'planned'];
        $this->store->write('apache-plan-'.$id, $plan);
        return $plan + ['impact'=>$view['impact'], 'diff'=>$view['git_diff']];
    }

    public function __invoke(string $operation, string $id): array
    {
        if (!preg_match('/\A[a-f0-9]{24}\z/D', $id)) throw new OpException('Apache計画IDが不正です');
        $key = 'apache-plan-'.$id;
        $plan = $this->store->read($key) ?: throw new OpException('Apache計画がありません');
        $site = $plan['site'];
        if ($operation === 'check' || $operation === 'prepare') {
            if ($plan['phase'] !== 'planned') throw new OpException('確定済みのApache計画は再利用できません');
            $this->recovery->assertIdle();
            [$vhost, $params] = $this->target($site);
            $view = $vhost->preview($params);
            if (!$view['can_apply'] || $view['conflicts'] || !hash_equals($plan['review'], $view['change_review'])) {
                throw new OpException('Apache設定が計画時から変更されています');
            }
            if ($operation === 'prepare') {
                $vhost->apply($params + ['require_change_review'=>true, 'change_review'=>$plan['review']], $id);
            }
            return [$site, ':apache-change'];
        }

        $lock = fopen($this->cfg['apache_sites'].'/.pjas-vhost.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new OpException('公開設定をロックできません');
        try {
            $plan = $this->store->read($key);
            $owner = $this->recovery->status()['owner'] ?? null;
            if (in_array($operation, ['rollback','check-rollback'], true) && !in_array($plan['phase'], ['planned','rolled-back'], true)) {
                throw new OpException('確定後の一括切り戻しはできません');
            }
            if ($operation === 'check-commit') $this->recovery->checkPrepared($id);
            if ($operation === 'check-rollback' && $owner === $id) $this->recovery->checkRollback();
            if ($operation === 'rollback') {
                if ($owner === $id) $this->recovery->rollback($this->reload(...));
                $plan['phase'] = 'rolled-back';
                $this->store->write($key, $plan);
            }
            if ($operation === 'commit' && $plan['phase'] !== 'committed') {
                if ($plan['phase'] === 'planned') {
                    $this->recovery->checkPrepared($id);
                    $plan['phase'] = 'committing';
                    $this->store->write($key, $plan);
                }
                if ($owner === $id) $this->recovery->complete();
                $plan['phase'] = 'committed';
                $this->store->write($key, $plan);
            }
        } finally {
            fclose($lock);
        }
        return [$site, ':apache-change'];
    }

    private function target(string $site): array
    {
        if (Components::protectedSite($this->cfg, $site) || $this->store->read('upgrade-active-'.$site)) {
            throw new OpException('コンポーネントまたは移行中のサイトはApache単独変更の対象外です');
        }
        $bundle = ($this->database)('read', $site, []);
        if ($bundle['pending_jobs']) throw new OpException('サイトに未完了の作業があります');
        $scope = (new SiteIsolation($this->cfg))->scope($site);
        $vhost = new Vhost($scope, new Paths($scope['www_root']));
        $path = $vhost->confPath($site);
        if (is_link($path) || !is_file($path) || !str_starts_with((string)file_get_contents($path), Vhost::MANAGED_HEADER)
            || !is_link($vhost->enabledDir().'/'.basename($path))
            || is_file($scope['apache_sites'].'/.pjas-inherited-'.$site.'.json')) {
            throw new OpException('公開済みの標準VirtualHostを指定してください');
        }
        return [$vhost, ['service'=>$bundle['spec']]];
    }

    private function reload(): void
    {
        Proc::must([$this->cfg['apachectl'],'configtest'], null, 30);
        Proc::must([$this->cfg['apachectl'],'graceful'], null, 60);
    }
}
