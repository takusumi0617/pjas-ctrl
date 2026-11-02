<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentClient;
use Pjas\Domain\JobRepo;
use Pjas\Domain\ServiceRepo;
use Pjas\Http\View;
use Pjas\Support\Db;

final class DashboardController
{
    public function index(): void
    {
        $services = ServiceRepo::all();
        $jobs     = JobRepo::recent(10);
        foreach ($jobs as $i => $j) {
            if (!in_array($j['state'], ['succeeded', 'failed'], true)) {
                $jobs[$i] = (JobRepo::sync((int) $j['id']) ?? $j) + ['service' => $j['service']];
            }
        }

        $counts = [
            'total'     => count($services),
            'healthy'   => count(array_filter($services, fn($s) => $s['state'] === 'healthy')),
            'unhealthy' => count(array_filter($services, fn($s) => $s['state'] === 'unhealthy')),
            'week'      => (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE type='deploy' AND started_at > datetime('now','-7 days')")['c'] ?? 0),
        ];

        if(!\Pjas\Http\Auth::can('owner')){
            $ids=array_column($services,'id');$counts['week']=$ids?(int)Db::one("SELECT COUNT(*) c FROM jobs WHERE type='deploy' AND started_at>datetime('now','-7 days') AND service_id IN (".implode(',',array_fill(0,count($ids),'?')).')',$ids)['c']:0;
        }
        View::render('dashboard', [
            'title'    => 'ホーム',
            'services' => $services,
            'jobs'     => $jobs,
            'counts'   => $counts,
            'agentOk'  => AgentClient::alive(),
        ]);
    }
}
