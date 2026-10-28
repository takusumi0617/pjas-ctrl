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
    public function pause(array $a): void
    {
        $s = ServiceRepo::findOrFail($a['name']);
        $actor = Auth::actor();
        $action = (string) ($_POST['action'] ?? '');
        $params = ['service' => Deployer::spec($s), 'action' => $action,
            'code' => $_POST['code'] ?? null, 'html' => (string) ($_POST['html'] ?? '')];
        try { $result = \Pjas\Agent\AgentClient::call('apache.pause', $params, $actor); }
        catch (AgentException $e) {
            Audit::log($actor, 'service.pause', $s['name'], ['action' => $action], 'failed');
            View::redirect('/services/' . $s['name'], $e->getMessage(), 'err');
        }
        Audit::log($actor, $result['enabled'] ? 'service.pause' : 'service.resume', $s['name'], ['code' => $result['code']]);
        View::redirect('/services/' . $s['name'], $result['enabled'] ? 'サイトを一時停止しました。指定したHTTPコードとHTMLを返します。' : 'サイトの公開を再開しました');
    }

    /** 生成 → 書き出し → a2ensite → configtest → graceful。失敗時は agent が元に戻す。 */
    public function apply(array $a): void
    {
        $s     = ServiceRepo::findOrFail($a['name']);
        $actor = Auth::actor();
        $job   = JobRepo::create((int) $s['id'], 'vhost', $actor, 'apply');

        try {
            $r = \Pjas\Agent\AgentClient::call('apache.apply', [
                'job_id'  => $job,
                'migration_source' => (string) ($_POST['migration_source'] ?? ''),
                'migration_hash' => (string) ($_POST['migration_hash'] ?? ''),
                'service' => Deployer::spec($s),
            ], $actor);
        } catch (AgentException $e) {
            Db::run("UPDATE jobs SET state='failed', detail=?, finished_at=datetime('now') WHERE id=?",
                [$e->getMessage(), $job]);
            Audit::log($actor, 'apache.vhost.apply', $s['name'], [], 'failed');
            View::redirect('/services/' . $s['name'] . '?tab=vhost', '公開設定の反映に失敗しました: ' . $e->getMessage(), 'err');
        }

        Db::run("UPDATE jobs SET state='succeeded', detail=?, finished_at=datetime('now') WHERE id=?",
            [(string) ($r['configtest'] ?? 'Syntax OK'), $job]);
        Db::insert('INSERT INTO vhosts (service_id, rendered_hash, applied_by) VALUES (?,?,?)',
            [$s['id'], (string) ($r['hash'] ?? ''), $actor]);
        $disabled = array_values((array) ($r['disabled'] ?? []));
        Audit::log($actor, 'apache.vhost.apply', $s['name'],
            ['hash' => $r['hash'] ?? '', 'disabled' => $disabled]);

        $msg = '公開設定を反映しました。サイトは止まらずに切り替わっています';
        if ($disabled) {
            $msg .= '。同じアドレスを使っていた古い設定を無効にしました: ' . implode(', ', $disabled);
        }
        View::redirect('/services/' . $s['name'] . '?tab=vhost', $msg);
    }

}
