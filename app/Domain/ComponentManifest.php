<?php
declare(strict_types=1);
namespace Pjas\Domain;
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';

/** v2 declarations contain references, never operating-system identities or secret values. */
final class ComponentManifest
{
    public static function keys(array $row, array $keys): void
    {
        foreach ($row as $key => $_) if (!in_array($key, $keys, true)) throw new \InvalidArgumentException('v2: 未知の項目 ' . $key);
    }
    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $id)) throw new \InvalidArgumentException('v2: 参照IDが不正です');
        return $id;
    }
    public static function items(mixed $items, int $limit = 32): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > $limit) throw new \InvalidArgumentException('v2: 配列または件数が不正です');
        return $items;
    }
    public static function parse(array $m): array
    {
        self::keys($m, ['version','site','components','requirements','backup_policy']);
        if (!is_array($m['site'] ?? null)) throw new \InvalidArgumentException('siteが必要です');
        self::keys($m['site'], ['name','domain','runtime','document_root','https','health_path','health_status']);
        // Reuse all version-1 site validation and defaults without allowing legacy hooks.
        $v1 = PjasManifest::parse(json_encode(['version'=>1,'site'=>$m['site']], JSON_THROW_ON_ERROR));
        $m['site'] = $v1['site'];
        foreach (['domain','runtime','document_root'] as $key) if (!is_string($m['site'][$key]??null)) throw new \InvalidArgumentException('v2のsite.'.$key.'が必要です');
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+\z/D',$m['site']['domain']) || strlen($m['site']['domain'])>253) throw new \InvalidArgumentException('site.domainが不正です');
        if (!in_array($m['site']['runtime'],\Pjas\Shared\PhpRuntime::RUNTIMES,true)) throw new \InvalidArgumentException('site.runtimeが不正です');
        if ($m['site']['document_root']!=='') PjasManifest::path($m['site']['document_root']);
        if (isset($m['site']['health_status']) && ($m['site']['health_status']<100 || $m['site']['health_status']>599)) throw new \InvalidArgumentException('site.health_statusが不正です');

        $seen = [];
        foreach (self::items($m['components'] ?? null, 16) as $c) {
            if (!is_array($c)) throw new \InvalidArgumentException('componentが不正です');
            self::keys($c, ['id','type','execution_profile','command','working_directory','storage','secrets','depends_on','health_checks','setup','existing_service']);
            $id = self::id($c['id'] ?? null);
            if (isset($seen[$id])) throw new \InvalidArgumentException('component IDが重複しています');
            if (!in_array($c['type'] ?? null, ['php','worker'], true)) throw new \InvalidArgumentException('component.typeが不正です');
            self::id($c['execution_profile'] ?? null);
            $c['command'] ??= [];
            self::items($c['command']);
            if ($c['type'] === 'worker' && !$c['command'] && empty($c['existing_service'])) throw new \InvalidArgumentException('worker.commandが必要です');
            if ($c['type'] === 'php' && ($c['command'] || isset($c['existing_service']))) throw new \InvalidArgumentException('PHPコンポーネントのcommand指定は禁止です');
            foreach ($c['command'] as $arg) if (!is_string($arg) || strlen($arg) > 1024 || preg_match('/[\x00-\x1f\x7f$%]/', $arg)) throw new \InvalidArgumentException('commandは展開なしの引数配列で指定してください');
            if ($c['command'] && !preg_match('~\A/[a-zA-Z0-9_./-]+\z~D', $c['command'][0])) throw new \InvalidArgumentException('実行ファイルは絶対パスで指定してください');
            $c['working_directory'] ??= '';
            if ($c['working_directory'] !== '') { if (!is_string($c['working_directory'])) throw new \InvalidArgumentException('working_directoryが不正です'); PjasManifest::path($c['working_directory']); }
            foreach (['storage','secrets','depends_on','setup'] as $key) {
                $c[$key] = self::items($c[$key] ?? []);
                foreach ($c[$key] as $ref) self::id($ref);
                if (count(array_unique($c[$key])) !== count($c[$key])) throw new \InvalidArgumentException('参照が重複しています');
            }
            if (isset($c['existing_service'])) self::id($c['existing_service']);
            $c['health_checks'] = self::items($c['health_checks'] ?? [], 8);
            foreach ($c['health_checks'] as $h) self::id($h); // administrator-owned probe definitions
            $seen[$id] = $c;
        }
        if (!$seen || count(array_filter($seen, fn($c)=>$c['type']==='php')) > 1) throw new \InvalidArgumentException('componentsは必須、PHPは1件までです');
        $done = []; $active = []; $order = [];
        $visit = function ($id) use (&$visit, &$done, &$active, &$order, $seen) {
            if (!isset($seen[$id])) throw new \InvalidArgumentException('未知の依存コンポーネント');
            if (isset($active[$id])) throw new \InvalidArgumentException('依存関係が循環しています');
            if (isset($done[$id])) return;
            $active[$id] = true;
            foreach ($seen[$id]['depends_on'] as $dep) $visit($dep);
            unset($active[$id]); $done[$id] = true; $order[] = $seen[$id];
        };
        foreach (array_keys($seen) as $id) $visit($id);
        $php=count(array_filter($order,fn($c)=>$c['type']==='php'));
        if (($m['site']['runtime']==='static') !== ($php===0)) throw new \InvalidArgumentException('PHPランタイムには専用PHPコンポーネントが必要です');
        $m['components'] = $order;
        $m['requirements'] = self::items($m['requirements'] ?? []);
        foreach ($m['requirements'] as $ref) self::id($ref);
        if (isset($m['backup_policy'])) self::id($m['backup_policy']);
        return $m;
    }
}
