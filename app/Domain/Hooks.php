<?php
declare(strict_types=1);

namespace Pjas\Domain;
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';

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
    public const ALLOWED = \Pjas\Shared\PhpRuntime::HOOKS;
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
        if(preg_match('~^/usr/bin/php([0-9]+\.[0-9]+) ~',$cmd,$m)&&!in_array('php'.$m[1].'-fpm',\Pjas\Shared\PhpRuntime::RUNTIMES,true))return false;
        foreach (self::ALLOWED as $re) {
            if (preg_match($re, $cmd)) {
                return true;
            }
        }
        return false;
    }
}
