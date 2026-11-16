<?php
declare(strict_types=1);
namespace Pjas\Domain;
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';

/** .pjas version 1 / 2。未知のキーは黙って無視せず、入力ミスとして返す。 */
final class PjasManifest
{
    private static function keys(array $v, array $allowed, string $where): void
    {
        foreach (array_keys($v) as $key) if (!in_array($key, $allowed, true)) throw new \InvalidArgumentException("{$where}: 未対応の項目 {$key}");
    }
    public static function path(string $path): string
    {
        if (!preg_match('~\A[A-Za-z0-9_./-]+\z~D', $path) || str_starts_with($path, '/') || str_contains($path, '..')) throw new \InvalidArgumentException('リポジトリ内の相対パスを指定してください');
        return $path;
    }
    public static function parse(string $json): array
    {
        if (strlen($json) > 128 * 1024) throw new \InvalidArgumentException('.pjasは128KB以内にしてください');
        try { $m = json_decode($json, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new \InvalidArgumentException('.pjasのJSONが不正です: ' . $e->getMessage()); }
        if (is_array($m) && ($m['version'] ?? null) === 2) { require_once __DIR__ . '/ComponentManifest.php'; return ComponentManifest::parse($m); }
        if (!is_array($m) || ($m['version'] ?? null) !== 1) throw new \InvalidArgumentException('.pjasのversionは1を指定してください');
        self::keys($m, ['version', 'site', 'apache', 'shared', 'prepare', 'daemons', 'cron'], '.pjas');
        if (!is_array($m['site'] ?? null)) throw new \InvalidArgumentException('siteを指定してください');
        self::keys($m['site'], ['name', 'domain', 'runtime', 'document_root', 'https', 'health_path', 'health_status'], 'site');
        if (!is_string($m['site']['name'] ?? null) || !preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $m['site']['name'])) throw new \InvalidArgumentException('site.nameが不正です');
        foreach (['domain','runtime','document_root','health_path'] as $key) if (isset($m['site'][$key]) && !is_string($m['site'][$key])) throw new \InvalidArgumentException('site.' . $key . 'は文字列で指定してください');
        if (isset($m['site']['https']) && !is_bool($m['site']['https'])) throw new \InvalidArgumentException('site.httpsはtrue/falseで指定してください');
        if (isset($m['site']['health_status']) && !is_int($m['site']['health_status'])) throw new \InvalidArgumentException('site.health_statusは整数で指定してください');
        if (isset($m['apache'])) {
            if (!is_array($m['apache'])) throw new \InvalidArgumentException('apacheはオブジェクトで指定してください');
            self::keys($m['apache'], ['file', 'source_root'], 'apache');
            if (!is_string($m['apache']['file'] ?? null)) throw new \InvalidArgumentException('apache.fileを文字列で指定してください');
            self::path($m['apache']['file']);
            $root = $m['apache']['source_root'] ?? '/var/www/' . $m['site']['name'];
            if (!is_string($root) || !preg_match('~\A/(?:[A-Za-z0-9_-]+/)*www/[A-Za-z0-9_-]+\z~D', $root)) throw new \InvalidArgumentException('apache.source_rootは /…/www/プロジェクト名 の形式で指定してください');
            $m['apache']['source_root'] = $root;
        }
        foreach (['shared', 'prepare', 'daemons', 'cron'] as $list) {
            $m[$list] ??= [];
            if (!is_array($m[$list]) || !array_is_list($m[$list]) || count($m[$list]) > 50) throw new \InvalidArgumentException("{$list}は50件以内の配列で指定してください");
        }
        foreach ($m['shared'] as $p) { if (!is_string($p) || $p === '.') throw new \InvalidArgumentException('sharedは相対パスの配列です'); self::path($p); }
        foreach ($m['prepare'] as $cmd) if (!is_string($cmd) || !Hooks::isAllowed($cmd)) throw new \InvalidArgumentException('prepareに許可されていないコマンドがあります');
        foreach (['daemons', 'cron'] as $kind) {
            $seen = [];
            foreach ($m[$kind] as $item) {
                if (!is_array($item)) throw new \InvalidArgumentException("{$kind}の項目はオブジェクトで指定してください");
                self::keys($item, $kind === 'daemons' ? ['name','label','command','working_directory','start_on_install','start_on_boot'] : ['name','label','schedule','command','enabled'], $kind);
                $name = $item['name'] ?? '';
                if (!is_string($name) || !preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $name) || isset($seen[$name])) throw new \InvalidArgumentException("{$kind}のnameが不正または重複しています");
                $seen[$name] = true;
                if (!is_string($item['command'] ?? null) || trim($item['command']) === '' || strlen($item['command']) > 4000 || str_contains($item['command'], "\0")) throw new \InvalidArgumentException("{$kind}.commandが不正です");
                if (isset($item['working_directory']) && $item['working_directory'] !== '') self::path($item['working_directory']);
                if ($kind === 'cron' && !ScheduledTaskRepo::validSchedule((string) ($item['schedule'] ?? ''))) throw new \InvalidArgumentException('cron.scheduleが不正です');
                foreach (['start_on_install','start_on_boot','enabled'] as $flag) if (isset($item[$flag]) && !is_bool($item[$flag])) throw new \InvalidArgumentException("{$flag}はtrue/falseで指定してください");
                if (isset($item['label']) && (!is_string($item['label']) || trim($item['label']) === '' || strlen($item['label']) > 240 || preg_match('/[\x00-\x1f]/',$item['label']))) throw new \InvalidArgumentException('表示名は80文字程度以内で指定してください');
            }
        }
        return $m;
    }
    public static function command(string $command, array $m, string $targetBase): string
    {
        $base = $m['apache']['source_root'] ?? '/var/www/' . $m['site']['name'];
        $pattern = '~' . preg_quote($base,'~') . '(?:(/shared)|/(?:current|releases/[0-9]{8}-[0-9]{6}))?(?=/|[\s"\x27]|$)~';
        return preg_replace_callback($pattern, static fn($v)=>!empty($v[1]) ? $targetBase . '/shared' : '{{site_root}}', $command);
    }
    public static function defaults(array $m): array
    {
        $s = $m['site'];
        return ['name'=>$s['name'], 'domain'=>$s['domain'] ?? '', 'runtime'=>$s['runtime'] ?? \Pjas\Shared\PhpRuntime::DEFAULT_RUNTIME,
            'docroot'=>$s['document_root'] ?? 'public', 'tls'=>($s['https'] ?? true) ? 1 : 0,
            'health_path'=>$s['health_path'] ?? '/', 'health_status'=>$s['health_status'] ?? 200,
            'shared_paths'=>implode(',', $m['shared'] ?? []), 'hooks'=>implode("\n", $m['prepare'] ?? [])];
    }
}
