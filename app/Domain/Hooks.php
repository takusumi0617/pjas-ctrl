<?php
declare(strict_types=1);

namespace Pjas\Domain;

/**
 * after_deploy フックの許可リスト。
 *
 * 任意コマンドは実行させない。ここに載っているパターンに完全一致するものだけ、
 * agent がリリースディレクトリ内で実行する。web 側でも同じ判定を行い、
 * 保存時点で弾く (二重チェック: agent 側が最終権限)。
 */
final class Hooks
{
    /** 完全一致の正規表現。引数も含めて縛る。 */
    public const ALLOWED = [
        '/^\/usr\/bin\/php8\.[1-4] \/usr\/bin\/composer install( --no-dev)?( -o| --optimize-autoloader)?( --no-interaction)?$/',
        '/^\/usr\/bin\/php8\.[1-4] \/usr\/bin\/composer dump-autoload( -o| --optimize)?$/',
        '/^\/usr\/bin\/php8\.[1-4] artisan (migrate --force|config:cache|route:cache|view:cache|storage:link|optimize|optimize:clear)$/',
        '/^composer install( --no-dev)?( -o| --optimize-autoloader)?( --no-interaction)?$/',
        '/^composer dump-autoload( -o| --optimize)?$/',
        '/^php artisan (migrate --force|config:cache|route:cache|view:cache|storage:link|optimize|optimize:clear)$/',
        '/^php bin\/console (cache:clear|doctrine:migrations:migrate --no-interaction)$/',
        '/^npm ci( --omit=dev)?$/',
        '/^npm run build$/',
    ];

    public static function isAllowed(string $cmd): bool
    {
        $cmd = trim($cmd);
        if ($cmd === '' || $cmd !== preg_replace('/\s+/', ' ', $cmd)) {
            return false;
        }
        // シェルメタ文字は無条件で拒否 (agent は exec 配列で実行するが念のため)
        if (preg_match('/[;&|`$><\n\r\\\\]/', $cmd)) {
            return false;
        }
        foreach (self::ALLOWED as $re) {
            if (preg_match($re, $cmd)) {
                return true;
            }
        }
        return false;
    }
}
