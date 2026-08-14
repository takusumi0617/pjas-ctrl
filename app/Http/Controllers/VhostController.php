<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Agent\AgentException;
use Pjas\Domain\Deployer;
use Pjas\Domain\JobRepo;
use Pjas\Domain\ServiceRepo;
use Pjas\Http\Auth;
use Pjas\Http\View;
use Pjas\Support\Audit;
use Pjas\Support\Db;

final class VhostController
{
    /** 生成 → 書き出し → a2ensite → configtest → graceful。失敗時は agent が元に戻す。 */
    public function apply(array $a): void
    {
        $s     = ServiceRepo::findOrFail($a['name']);
        $actor = Auth::actor();
        $job   = JobRepo::create((int) $s['id'], 'vhost', $actor, 'apply');

        try {
            $r = \Pjas\Agent\AgentClient::call('apache.apply', [
                'job_id'  => $job,
                'service' => Deployer::spec($s),
            ], $actor);
        } catch (AgentException $e) {
            Db::run("UPDATE jobs SET state='failed', detail=?, finished_at=datetime('now') WHERE id=?",
                [$e->getMessage(), $job]);
            Audit::log($actor, 'apache.vhost.apply', $s['name'], [], 'failed');
            View::redirect('/services/' . $s['name'] . '?tab=vhost', 'vhost 適用に失敗しました: ' . $e->getMessage(), 'err');
        }

        Db::run("UPDATE jobs SET state='succeeded', detail=?, finished_at=datetime('now') WHERE id=?",
            [(string) ($r['configtest'] ?? 'Syntax OK'), $job]);
        Db::insert('INSERT INTO vhosts (service_id, rendered_hash, applied_by) VALUES (?,?,?)',
            [$s['id'], (string) ($r['hash'] ?? ''), $actor]);
        Audit::log($actor, 'apache.vhost.apply', $s['name'], ['hash' => $r['hash'] ?? '']);

        View::redirect('/services/' . $s['name'] . '?tab=vhost', 'vhost を適用し、Apache を graceful リロードしました');
    }

    public function tls(array $a): void
    {
        $s     = ServiceRepo::findOrFail($a['name']);
        $actor = Auth::actor();
        try {
            $r = \Pjas\Agent\AgentClient::call('tls.issue', ['service' => Deployer::spec($s)], $actor);
        } catch (AgentException $e) {
            Audit::log($actor, 'tls.issue', $s['name'], [], 'failed');
            View::redirect('/services/' . $s['name'] . '?tab=vhost', '証明書の取得に失敗しました: ' . $e->getMessage(), 'err');
        }
        Audit::log($actor, 'tls.issue', $s['name'], ['expires' => $r['expires'] ?? '']);
        View::redirect('/services/' . $s['name'] . '?tab=vhost',
            '証明書を取得しました。TLS を有効にして vhost を再適用してください。');
    }
}
