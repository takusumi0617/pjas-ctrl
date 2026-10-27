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
        'ping',
        'service.scan', 'service.create', 'service.destroy',
        'deploy.start', 'deploy.rollback', 'deploy.adopt',
        'apache.render', 'apache.apply', 'apache.reload',
        'fpm.reload',
        'fs.list', 'fs.read', 'fs.write',
        'health.check',
        'cron.apply', 'cron.delete',
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
