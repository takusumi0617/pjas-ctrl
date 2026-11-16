<?php
declare(strict_types=1);
namespace Pjas\Shared;

/** The single allowlist for PHP versions. Availability is checked separately. */
final class PhpRuntime
{
    public const DEFAULT_VERSION = '8.5';
    public const DEFAULT_RUNTIME = 'php8.5-fpm';
    public const RUNTIMES = ['php8.5-fpm','php8.6-fpm','php8.4-fpm','php8.3-fpm','php8.2-fpm','php8.1-fpm','static'];

    public const HOOKS = [
        '/^\/usr\/bin\/php8\.[0-9]+ \/usr\/bin\/composer install( --no-dev)?( -o| --optimize-autoloader)?( --no-interaction)?$/',
        '/^\/usr\/bin\/php8\.[0-9]+ \/usr\/bin\/composer dump-autoload( -o| --optimize)?$/',
        '/^\/usr\/bin\/php8\.[0-9]+ artisan (migrate --force|config:cache|route:cache|view:cache|storage:link|optimize|optimize:clear)$/',
        '/^composer install( --no-dev)?( -o| --optimize-autoloader)?( --no-interaction)?$/',
        '/^composer dump-autoload( -o| --optimize)?$/',
        '/^php artisan (migrate --force|config:cache|route:cache|view:cache|storage:link|optimize|optimize:clear)$/',
        '/^php bin\/console (cache:clear|doctrine:migrations:migrate --no-interaction)$/',
        '/^npm ci( --omit=dev)?$/',
        '/^npm run build$/',
    ];


    public static function version(string $runtime): string
    {
        if ($runtime === 'static' || !in_array($runtime,self::RUNTIMES,true)) throw new \InvalidArgumentException('未登録のPHPランタイムです');
        return substr($runtime,3,-4);
    }
    public static function cli(string $runtime): string { return '/usr/bin/php'.self::version($runtime); }
    public static function fpm(string $runtime): string { return '/usr/sbin/php-fpm'.self::version($runtime); }
    public static function controlVersion(): string
    {
        $path='/etc/pjas-control/php-version';
        if(!is_file($path))return self::DEFAULT_VERSION;
        $st=lstat($path);
        if(is_link($path)||$st['uid']!==0||($st['mode']&0022))throw new \RuntimeException('管理用PHPの設定はroot所有・書き込み禁止にしてください');
        $version=trim((string)file_get_contents($path));self::version('php'.$version.'-fpm');return $version;
    }
    public static function renderTemplate(string $body,string $version): string
    {
        self::version('php'.$version.'-fpm');
        return str_replace(self::DEFAULT_VERSION,$version,$body);
    }
    public static function command(string $command,string $runtime): string
    {
        if($runtime==='static')return $command;
        return preg_replace_callback('~^(\s*(?:exec\s+)?)(?:/usr/bin/)?php(?:8\.[0-9]+)?(?=\s|$)~m',static fn($m)=>$m[1].self::cli($runtime),$command);
    }
    public static function catalog(): array
    {
        return array_map(static fn($r)=>['runtime'=>$r,'available'=>$r==='static'||(is_executable(self::cli($r))&&is_executable(self::fpm($r))), 'default'=>$r===self::DEFAULT_RUNTIME],self::RUNTIMES);
    }
    public static function requireInstalled(string $runtime): void
    {
        if ($runtime !== 'static' && (!is_executable(self::cli($runtime)) || !is_executable(self::fpm($runtime)))) throw new \RuntimeException($runtime.' のCLIとFPMを先にインストールしてください');
    }
}
