<?php
declare(strict_types=1);

namespace Pjas\Agent;

use Pjas\Support\Config;

/**
 * 特権デーモン pjas-agentd への型付き RPC クライアント。
 *
 * Web プロセス (www-data) は root を持たない。ここで送れるのは
 * 「op 名 + パラメータ」だけで、任意のコマンド文字列は送れない。
 */
final class AgentClient
{
    /** agent 側 Registry と一致する許可 op。ここに無い op は送信前に落とす。 */
    public const OPS = [
        'site.plan','site.status','site.start','site.cleanup',
        'ops.run','ops.settings','ops.save','ops.notify','ops.resources','ops.certificate','ops.logs',
        'github.app.status','github.app.save','github.app.convert','github.app.test','github.webhook.verify','github.connection',
        'backup.list','backup.create','backup.restore','backup.download','backup.key','backup.prune',
        'deploy.cleanup','deploy.prepare','deploy.review','deploy.preparations','deploy.diff','apache.restore','apache.history',
        'preview.save','preview.status','preview.publish','preview.credentials','preview.remove',
        'ping', 'github.validate', 'github.inspect', 'github.configure',
        'service.scan', 'service.create', 'service.destroy',
        'deploy.start', 'deploy.rollback', 'deploy.adopt', 'deploy.releases',
        'apache.pause', 'apache.pause.status',
        'apache.render', 'apache.apply', 'apache.reload',
        'fpm.reload',
        'fs.list', 'fs.read', 'fs.write',
        'health.check',
        'cron.apply', 'cron.delete',
        'daemon.list', 'daemon.action', 'daemon.candidates', 'daemon.save',
    ];

    /**
     * @throws AgentException
     */
    public static function call(string $op, array $params = [], string $actor = 'system'): array
    {
        if (!in_array($op, self::OPS, true)) {
            throw new AgentException("未許可の op: {$op}");
        }

        $socket  = (string) Config::get('agent_socket');
        $timeout = (int) Config::get('agent_timeout', 900);

        $fp = @stream_socket_client('unix://' . $socket, $errno, $errstr, 10);
        if (!$fp) {
            $why = trim((string) $errstr) !== '' ? $errstr : "errno {$errno}";
            throw new AgentException("pjas-agentd に接続できません ({$socket}): {$why}");
        }
        stream_set_timeout($fp, $timeout);

        $req = json_encode([
            'op'         => $op,
            'params'     => $params,
            'actor'      => $actor,
            'request_id' => bin2hex(random_bytes(8)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        fwrite($fp, $req . "\n");
        $line = fgets($fp);
        fclose($fp);

        if ($line === false) {
            throw new AgentException("pjas-agentd から応答がありません (op={$op})");
        }
        $res = json_decode(trim($line), true);
        if (!is_array($res)) {
            throw new AgentException("pjas-agentd の応答を解釈できません: " . substr($line, 0, 200));
        }
        if (empty($res['ok'])) {
            throw new AgentException($res['error'] ?? "op {$op} が失敗しました");
        }
        return $res['data'] ?? [];
    }

    public static function alive(): bool
    {
        try {
            self::call('ping');
            return true;
        } catch (AgentException) {
            return false;
        }
    }
}
