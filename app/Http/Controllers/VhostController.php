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
            'code' => $_POST['code'] ?? null, 'page_mode'=>(string)($_POST['page_mode'] ?? 'custom'), 'html' => (string) ($_POST['html'] ?? '')];
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
        $pending=\Pjas\Domain\SettingsChanges::pending((int)$s['id']);
        $job   = JobRepo::create((int) $s['id'], 'vhost', $actor, 'apply');

        try {
            $mode=($_POST['update_mode']??'auto')==='settings'?'settings':'auto';
            if($mode==='settings' && !hash_equals(hash('sha256',json_encode(Deployer::spec($s),JSON_THROW_ON_ERROR)),(string)($_POST['spec_hash']??'')))throw new AgentException('保存内容がプレビュー後に変更されました。変更点を開き直してください');
            $r = \Pjas\Agent\AgentClient::call('apache.apply', [
                'job_id'  => $job,
                'update_mode' => $mode,
                'current_hash' => (string) ($_POST['current_hash'] ?? ''),
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
        if($mode==='settings'&&$pending)\Pjas\Domain\SettingsChanges::clearIfCurrent((int)$s['id'],$pending);
        Audit::log($actor, 'apache.vhost.apply', $s['name'],
            ['hash' => $r['hash'] ?? '', 'disabled' => $disabled, 'update_mode' => $r['update_mode'] ?? 'auto', 'history_revision' => $r['history_revision'] ?? '']);

        $msg = ($r['update_mode'] ?? '') === 'errors'
            ? '標準エラーページだけ更新しました。現在のHTTP・HTTPSの独自設定は維持しています'
            : ($mode==='settings'?'保存した登録内容を公開設定へ反映しました':'公開設定を新しく作成しました');
        if ($disabled) {
            $msg .= '。同じアドレスを使っていた古い設定を無効にしました: ' . implode(', ', $disabled);
        }
        if (!empty($r['history_warning'])) $msg .= '。' . $r['history_warning'];
        View::redirect('/services/' . $s['name'] . '?tab=vhost', $msg, !empty($r['history_warning']) ? 'err' : 'ok');
    }

}
