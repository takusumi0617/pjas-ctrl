<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/**
 * web から届いたサービス仕様の検証。
 *
 * web 側にも同等の検証があるが、**信頼するのはこちらだけ**。
 * ここを通ったパスと値しか root 操作に使わない。
 */
final class Spec
{
    public const RUNTIMES = ['php8.4-fpm', 'php8.3-fpm', 'php8.2-fpm', 'php8.1-fpm', 'static'];

    public const ALLOWED_DIRECTIVES = [
        'Header', 'RequestHeader', 'Redirect', 'RedirectMatch',
        'RewriteEngine', 'RewriteCond', 'RewriteRule',
        'ErrorDocument', 'AddDefaultCharset', 'Options',
        'ProxyPassMatch', 'SetEnvIf', 'Timeout', 'LimitRequestBody',
    ];

    /** after_deploy フックの許可リスト (web 側 app/Domain/Hooks.php と同一) */
    public const ALLOWED_HOOKS = [
        '/^composer install( --no-dev)?( -o| --optimize-autoloader)?( --no-interaction)?$/',
        '/^composer dump-autoload( -o| --optimize)?$/',
        '/^php artisan (migrate --force|config:cache|route:cache|view:cache|storage:link|optimize|optimize:clear)$/',
        '/^php bin\/console (cache:clear|doctrine:migrations:migrate --no-interaction)$/',
        '/^npm ci( --omit=dev)?$/',
        '/^npm run build$/',
    ];

    public static function name(mixed $v): string
    {
        $v = is_string($v) ? $v : '';
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $v)) {
            throw new OpException("サービス名が不正です: " . substr($v, 0, 40));
        }
        return $v;
    }

    public static function domain(mixed $v): string
    {
        $v = is_string($v) ? strtolower($v) : '';
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $v) || strlen($v) > 253) {
            throw new OpException("ドメイン名が不正です: " . substr($v, 0, 60));
        }
        return $v;
    }

    /** リリース内の相対パス (docroot / shared) */
    public static function relPath(mixed $v, string $what): string
    {
        $v = is_string($v) ? trim($v, '/') : '';
        if ($v === '') {
            return '';
        }
        if (!preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $v) || str_contains($v, '..')) {
            throw new OpException("{$what} のパスが不正です: " . substr($v, 0, 60));
        }
        return $v;
    }

    public static function tag(mixed $v): string
    {
        $v = is_string($v) ? $v : '';
        if (!preg_match('/^\d{8}-\d{6}$/', $v)) {
            throw new OpException("リリースタグが不正です: " . substr($v, 0, 40));
        }
        return $v;
    }

    public static function hook(string $cmd): array
    {
        $cmd = trim($cmd);
        if (preg_match('/[;&|`$><\n\r\\\\]/', $cmd)) {
            throw new OpException("フックにシェルメタ文字は使えません: {$cmd}");
        }
        foreach (self::ALLOWED_HOOKS as $re) {
            if (preg_match($re, $cmd)) {
                return explode(' ', $cmd);      // argv 配列で実行する
            }
        }
        throw new OpException("許可されていないフックです: {$cmd}");
    }

    public static function extraConf(mixed $v): string
    {
        $v = is_string($v) ? $v : '';
        $out = [];
        foreach (preg_split('/\R/', $v) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, '<')) {
                throw new OpException("追加ディレクティブにセクションは書けません: {$line}");
            }
            $token = strtok($line, " \t");
            if (!in_array($token, self::ALLOWED_DIRECTIVES, true)) {
                throw new OpException("許可されていないディレクティブです: {$line}");
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /** サービス仕様全体を正規化する */
    public static function service(mixed $s): array
    {
        if (!is_array($s)) {
            throw new OpException('service パラメータがありません');
        }
        $runtime = (string) ($s['runtime'] ?? 'php8.4-fpm');
        if (!in_array($runtime, self::RUNTIMES, true)) {
            throw new OpException("ランタイムが不正です: {$runtime}");
        }
        $ao = (string) ($s['allow_override'] ?? 'None');
        if (!in_array($ao, ['None', 'FileInfo', 'All'], true)) {
            throw new OpException("AllowOverride が不正です: {$ao}");
        }

        $shared = [];
        foreach ((array) ($s['shared'] ?? []) as $p) {
            $p = self::relPath($p, 'shared');
            if ($p !== '') {
                $shared[] = $p;
            }
        }
        $hooks = [];
        foreach ((array) ($s['hooks'] ?? []) as $h) {
            $hooks[] = self::hook((string) $h);
        }

        return [
            'name'           => self::name($s['name'] ?? ''),
            'domain'         => self::domain($s['domain'] ?? 'localhost.localdomain'),
            'runtime'        => $runtime,
            'docroot'        => self::relPath($s['docroot'] ?? '', 'DocumentRoot'),
            'allow_override' => $ao,
            'extra_conf'     => self::extraConf($s['extra_conf'] ?? ''),
            'tls'            => !empty($s['tls']),
            'shared'         => $shared,
            'hooks'          => $hooks,
            'keep_releases'  => max(1, min(50, (int) ($s['keep_releases'] ?? 5))),
            'health_path'    => '/' . ltrim(preg_replace('/[^\x21-\x7e]/', '', (string) ($s['health_path'] ?? '/healthz')) ?? '', '/'),
            'health_status'  => max(100, min(599, (int) ($s['health_status'] ?? 200))),
        ];
    }
}
