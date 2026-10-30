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
        'ProxyPassMatch', 'ProxyPass', 'ProxyPassReverse', 'ProxyPreserveHost',
        'FallbackResource', 'SetEnvIf', 'Timeout', 'LimitRequestBody',
    ];

    /** after_deploy フックの許可リスト (web 側 app/Domain/Hooks.php と同一) */
    public const ALLOWED_HOOKS = [
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

    public static function taskId(mixed $v): int
    {
        $id = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new OpException('定期処理IDが不正です');
        }
        return (int) $id;
    }

    public static function cronSchedule(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if (in_array($v, ['@hourly', '@daily', '@weekly', '@monthly', '@yearly'], true)) {
            return $v;
        }
        $fields = preg_split('/\s+/', $v) ?: [];
        if (count($fields) !== 5) {
            throw new OpException('実行時刻は5項目で指定してください');
        }
        foreach ($fields as $field) {
            if (!preg_match('/^[0-9A-Za-z*\/,\-]+$/', $field)) {
                throw new OpException('実行時刻に使えない文字があります');
            }
        }
        return implode(' ', $fields);
    }

    public static function cronCommand(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || strlen($v) > 4000 || str_contains($v, "\0")) {
            throw new OpException('定期処理のコマンドが不正です');
        }
        return str_replace("\r\n", "\n", $v);
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

    /** Resolve validated hooks using the site's PHP, including previously saved hooks. */
    public static function runtimeHook(array $argv, string $runtime): array
    {
        if (!array_is_list($argv) || $argv === []) throw new OpException('フックの引数配列が不正です');
        foreach ($argv as $arg) {
            if (!is_string($arg) || $arg === '' || preg_match('/\s/', $arg)) throw new OpException('フックの引数配列が不正です');
        }
        $argv = self::hook(implode(' ', $argv));
        if (!in_array($runtime, self::RUNTIMES, true)) throw new OpException('ランタイムが不正です');
        if ($runtime === 'static') {
            if (str_starts_with($argv[0], '/usr/bin/php8.')) throw new OpException('PHPフックにはPHPランタイムを指定してください');
            return $argv;
        }
        $php = '/usr/bin/' . substr($runtime, 0, -4);
        if (str_starts_with($argv[0], '/usr/bin/php8.')) {
            if ($argv[0] !== $php) throw new OpException('フックのPHPとサイトのランタイムが異なります');
            return $argv;
        }
        if ($argv[0] === 'composer') return array_merge([$php, '/usr/bin/composer'], array_slice($argv, 1));
        if ($argv[0] === 'php') $argv[0] = $php;
        return $argv;
    }

    public static function extraConf(mixed $v): string
    {
        $v = is_string($v) ? $v : '';
        if (str_contains($v, "\0")) throw new OpException('追加設定に不正な文字が含まれています');
        $out = [];
        $directory = false;
        foreach (preg_split('/\R/', $v) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if ($line === '<Directory {{document_root}}>' && !$directory) {
                $directory = true;
                $out[] = $line;
                continue;
            }
            if ($line === '</Directory>' && $directory) {
                $directory = false;
                $out[] = $line;
                continue;
            }
            $token = strtok($line, " \t");
            if (str_starts_with($line, '<') || str_contains($line, "\0")
                || (!in_array($token, self::ALLOWED_DIRECTIVES, true)
                    && !($directory && $line === 'Require all granted'))
                || ($directory && !in_array($token, ['Header', 'FallbackResource', 'Options', 'Require', 'RewriteEngine', 'RewriteCond', 'RewriteRule'], true))) {
                throw new OpException("許可されていない追加設定です: {$line}");
            }
            $out[] = $line;
        }
        if ($directory) throw new OpException('Directory の閉じタグがありません');
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

        $autoDatabases = !empty($s['auto_databases']);
        $shared = [];
        foreach ((array) ($s['shared'] ?? []) as $p) {
            if ($p === '@databases') { $autoDatabases = true; continue; }
            $p = self::relPath($p, 'shared');
            if ($p !== '') {
                $shared[] = $p;
            }
        }
        // 親フォルダのリンク後に子パスを削除すると実体を消してしまうため、重複を畳む。
        usort($shared, static fn($a,$b) => strlen($a) <=> strlen($b));
        $parents = [];
        foreach (array_unique($shared) as $p) {
            foreach ($parents as $parent) if (str_starts_with($p, $parent . '/')) continue 2;
            $parents[] = $p;
        }
        $shared = $parents;
        $hooks = [];
        foreach ((array) ($s['hooks'] ?? []) as $h) {
            if (is_array($h)) {
                if (!array_is_list($h) || $h === []) throw new OpException('フックの引数配列が不正です');
                foreach ($h as $arg) if (!is_string($arg) || $arg === '' || preg_match('/\s/', $arg)) throw new OpException('フックの引数配列が不正です');
                $h = implode(' ', $h);
            }
            if (!is_string($h)) throw new OpException('フックが不正です');
            $hooks[] = self::hook($h);
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
            'auto_databases' => $autoDatabases,
            'hooks'          => $hooks,
            'keep_releases'  => max(1, min(50, (int) ($s['keep_releases'] ?? 5))),
            'health_path'    => '/' . ltrim(preg_replace('/[^\x21-\x7e]/', '', (string) ($s['health_path'] ?? '/healthz')) ?? '', '/'),
            'health_status'  => max(100, min(599, (int) ($s['health_status'] ?? 200))),
        ];
    }
}
