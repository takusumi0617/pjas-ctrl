<?php
declare(strict_types=1);

namespace Pjas\Support;

final class Audit
{
    /** 機微な値は記録しない。キー名のみ残す。 */
    private const REDACT = ['password', 'secret', 'token', 'key', 'value'];

    public static function log(string $actor, string $action, string $target = '', array $params = [], string $result = 'ok'): void
    {
        Db::insert(
            'INSERT INTO audit_log (actor, action, target, params, result, ip) VALUES (?,?,?,?,?,?)',
            [$actor, $action, $target, json_encode(self::redact($params), JSON_UNESCAPED_UNICODE), $result,
             $_SERVER['REMOTE_ADDR'] ?? 'local']
        );
    }

    private static function redact(array $params): array
    {
        foreach ($params as $k => $v) {
            foreach (self::REDACT as $needle) {
                if (stripos((string) $k, $needle) !== false) {
                    $params[$k] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($v)) {
                $params[$k] = self::redact($v);
            }
        }
        return $params;
    }
}
