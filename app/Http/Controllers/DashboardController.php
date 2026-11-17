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
        $presentation=\Pjas\Domain\SitePresentation::collect(ServiceRepo::all());
        $services=$presentation['services'];
        $jobs     = JobRepo::recent(10);
        foreach ($jobs as $i => $j) {
            if (!in_array($j['state'], ['succeeded', 'failed'], true)) {
                $jobs[$i] = (JobRepo::sync((int) $j['id']) ?? $j) + ['service' => $j['service']];
            }
        }

        $counts = [
            'total'     => count($services),
            'healthy'   => count(array_filter($services, fn($s) => $s['presentation']['healthy'])),
            'unhealthy' => count(array_filter($services, fn($s) => !$s['presentation']['healthy'])),
            'week'      => (int) (Db::one("SELECT COUNT(*) c FROM jobs WHERE type='deploy' AND started_at > datetime('now','-7 days')")['c'] ?? 0),
        ];

        if(!\Pjas\Http\Auth::can('owner')){
            $ids=array_column($services,'id');$counts['week']=$ids?(int)Db::one("SELECT COUNT(*) c FROM jobs WHERE type='deploy' AND started_at>datetime('now','-7 days') AND service_id IN (".implode(',',array_fill(0,count($ids),'?')).')',$ids)['c']:0;
        }
        $ids=array_column($services,'id');
        $alertWhere=\Pjas\Http\Auth::can('owner')?'1=1':($ids?'service_id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'1=0');
        $alerts=Db::all('SELECT * FROM alerts WHERE '.$alertWhere.' ORDER BY id DESC LIMIT 30',\Pjas\Http\Auth::can('owner')?[]:$ids);
        $controlUpdate=[];$controlUpdateError='';
        if(\Pjas\Http\Auth::can('owner'))try{$controlUpdate=AgentClient::call('control.status',[],\Pjas\Http\Auth::actor());}catch(\Pjas\Agent\AgentException $e){$controlUpdateError=$e->getMessage();}
        View::render('dashboard', [
            'controlUpdate'=>$controlUpdate,'controlUpdateError'=>$controlUpdateError,'controlReview'=>$_SESSION['control_update_review']??null,
            'title'    => 'ホーム','alerts'=>$alerts,
            'services' => $services,
            'jobs'     => $jobs,
            'counts'   => $counts,
            'agentOk'  => AgentClient::alive(),
            'observationError'=>$presentation['observationError'],
            'serverChecks'=>\Pjas\Http\Auth::can('owner')?Db::all("SELECT * FROM monitor_state WHERE service_id IS NULL AND ok=0"):[],
        ]);
    }
}
