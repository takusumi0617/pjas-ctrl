<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';

/** Plan a narrowly scoped upgrade without rewriting PHP, Apache or local drop-ins. */
final class SecurityUpdate
{
    /** Host-specific pjas-cert override reviewed on 2026-11-14.
     * Match the unit, file, sections and every value; never allow arbitrary
     * Environment or ReadWritePaths directives by their names alone.
     */
    public static function reviewedComponentDropIn(string $unit, string $path, string $body): bool
    {
        if (!preg_match('/\Apjas-component-pjas-cert-(web|ca|sign)\.service\z/D',$unit,$match)) return false;
        $component=$match[1];$dir='/etc/systemd/system/'.$unit.'.d/';
        if ($path===$dir.'pjas-cert.conf') {
            $expected=[
                'Unit.After'=>['systemd-tmpfiles-setup.service'],
                'Service.ReadWritePaths'=>['/run/pjas-cert/coordination.lock'],
                'Service.MemoryMax'=>['256M'],
                'Service.CPUQuota'=>['100%'],
            ];
            if ($component==='web') $expected['Service.Environment']=['PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d:/etc/pjas-cert/php-ini'];
            else $expected['Service.ReadWritePaths'][]='/run/pjas-cert/'.$component;
        } elseif ($path===$dir.'zzzz-php85-migration.conf') {
            // The empty ExecStart must precede the replacement. Reversing these
            // lines clears the command instead of selecting PHP 8.5.
            $command=$component==='web'
                ? '/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /run/pjas-components/pjas-cert-web/php-fpm.conf'
                : '/usr/bin/php8.5 bin/worker '.$component;
            $expected=['Service.ExecStart'=>['',$command]];
            if ($component==='web') $expected['Service.Environment']=['PHP_INI_SCAN_DIR=/etc/php/8.5/fpm/conf.d:/etc/pjas-cert/php-ini'];
        } else return false;
        $section=''; $seen=[];
        foreach (explode("\n",$body) as $line) {
            $line=trim($line);
            if ($line==='' || str_starts_with($line,'#') || str_starts_with($line,';')) continue;
            if (in_array($line,['[Unit]','[Service]'],true)) { $section=substr($line,1,-1);continue; }
            if (!str_contains($line,'=')) return false;
            [$key,$value]=explode('=',$line,2);$key=$section.'.'.trim($key);
            if (!isset($expected[$key])) return false;
            $seen[$key][]=trim($value);
        }
        if (count($seen)!==count($expected)) return false;
        foreach ($expected as $key=>$values) if (($seen[$key]??null)!==$values) return false;
        return true;
    }

    /** Prerequisites for the reviewed override; inspect only, never recreate
     * application-owned paths or rewrite PHP migration settings here.
     * @return array<string,string> path => file|directory|executable
     */
    public static function reviewedRequirements(string $unit,string $path,string $body): array
    {
        if (!self::reviewedComponentDropIn($unit,$path,$body)) return [];
        preg_match('/-(web|ca|sign)\.service\z/D',$unit,$match);$component=$match[1];
        if (basename($path)==='pjas-cert.conf') {
            $required=['/run/pjas-cert/coordination.lock'=>'file'];
            if ($component!=='web') $required['/run/pjas-cert/'.$component]='directory';
            return $required;
        }
        if ($component!=='web') return ['/usr/bin/php8.5'=>'executable'];
        return ['/usr/sbin/php-fpm8.5'=>'executable','/etc/php/8.5/fpm/conf.d'=>'directory','/etc/pjas-cert/php-ini'=>'directory'];
    }

    /** systemctl set-property persists resource-only files under system.control.
     * Retain those limits, but require explicit review of executable overrides.
     */
    public static function resourceDropIn(string $unit, string $path, string $body): bool
    {
        $prefix='/etc/systemd/system.control/'.$unit.'.d/50-';
        if (!str_starts_with($path,$prefix) || !preg_match('/\A(MemoryHigh|MemoryMax|TasksMax)\.conf\z/D',substr($path,strlen($prefix)),$m)) return false;
        $section=false; $found=false;
        foreach (explode("\n",$body) as $line) {
            $line=trim($line);
            if ($line==='' || str_starts_with($line,'#') || str_starts_with($line,';')) continue;
            if ($line==='[Service]' && !$section) { $section=true;continue; }
            if (!$section || !preg_match('/\A'.preg_quote($m[1],'/').'=(infinity|[0-9]+(?:\.[0-9]+)?[KMGTPE%]?)\z/D',$line)) return false;
            $found=true;
        }
        return $found;
    }

    public static function files(string $root, string $unitDir='/etc/systemd/system'): array
    {
        $files=[];
        foreach (['pjas-agentd','pjas-control-web'] as $name) {
            $files[$unitDir.'/'.$name.'.service.d/90-pjas-security.conf']=
                (string)file_get_contents($root.'/systemd/'.$name.'-security.conf');
        }
        $files[$unitDir.'/pjas-components-boot.service']=\Pjas\Shared\PhpRuntime::renderTemplate(str_replace('/opt/pjas-ctrl',$root,
            (string)file_get_contents($root.'/systemd/pjas-components-boot.service')),\Pjas\Shared\PhpRuntime::controlVersion());
        return $files;
    }

    /** Only disable autostart for exact, approved, ctrl-generated component units.
     * Other services, existing-service grants and local drop-ins remain untouched.
     */
    public static function managedUnits(array $cfg): array
    {
        $store=new ControlStore($cfg); $units=[];
        $unitDir=rtrim($cfg['daemon_unit_dir']??'/etc/systemd/system','/');
        foreach (glob($store->directory().'/components-*.json')?:[] as $file) {
            $site=Spec::name(substr(basename($file),11,-5));
            $s=$store->read('components-'.$site);
            foreach ($s['manifest']['components']??[] as $c) {
                if (isset($c['existing_service'])) continue;
                \Pjas\Domain\ComponentManifest::id($c['id']);
                $unit='pjas-component-'.$site.'-'.$c['id'].'.service';
                $path=$unitDir.'/'.$unit;
                $hash=$s['files'][$path]??null;
                ComponentPolicy::safe($path);
                if (!is_string($hash) || !hash_equals($hash,(string)hash_file('sha256',$path))) {
                    throw new OpException('生成unitに未承認の変更があります: '.$unit);
                }
                // A local drop-in can change dependencies or execution; review it first.
                $units[]=$unit;
            }
        }
        return array_values(array_unique($units));
    }
}
