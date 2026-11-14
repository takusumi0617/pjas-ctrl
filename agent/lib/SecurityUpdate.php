<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** Plan a narrowly scoped upgrade without rewriting PHP, Apache or local drop-ins. */
final class SecurityUpdate
{
    public static function files(string $root, string $unitDir='/etc/systemd/system'): array
    {
        $files=[];
        foreach (['pjas-agentd','pjas-control-web'] as $name) {
            $files[$unitDir.'/'.$name.'.service.d/90-pjas-security.conf']=
                (string)file_get_contents($root.'/systemd/'.$name.'-security.conf');
        }
        $files[$unitDir.'/pjas-components-boot.service']=str_replace('/opt/pjas-ctrl',$root,
            (string)file_get_contents($root.'/systemd/pjas-components-boot.service'));
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
