<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';
use Pjas\Agentd\{ControlStore,Proc,OpException};
require_once dirname(__DIR__).'/lib/ControlStore.php';
/** Single-host, bounded read-only collector. No user-provided commands or paths. */
final class ServerMonitor
{
    private ControlStore $store;
    public function __construct(private array $cfg,private $runner=null){$this->store=new ControlStore($cfg);}
    private function run(array $args,int $seconds=5): array { return $this->runner ? ($this->runner)($args) : Proc::run($args,null,$seconds,null,['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C','LC_ALL'=>'C']); }
    private function must(array $args,int $seconds): void { $result=$this->run($args,$seconds);if($result['code']!==0)throw new OpException('設定の検査・サービス操作に失敗しました。サービスの記録を確認してください'); }
    public static function memory(string $text): ?float
    {
        preg_match('/^MemTotal:\s+(\d+)/m',$text,$t);preg_match('/^MemAvailable:\s+(\d+)/m',$text,$a);
        return !empty($t[1])&&isset($a[1]) ? round(100*(1-(int)$a[1]/(int)$t[1]),1) : null;
    }
    public static function cpu(array $before,array $after): ?float
    {
        $delta=array_sum($after)-array_sum($before);return $before&&$delta>0?round(max(0,min(100,100*(1-(($after[3]??0)+($after[4]??0)-($before[3]??0)-($before[4]??0))/$delta))),1):null;
    }
    private function units(): array
    {
        $units=['apache2.service','php'.\Pjas\Shared\PhpRuntime::controlVersion().'-fpm.service','pjas-agentd.service','pjas-control-web.service','pjas-monitor.timer'];
        foreach(glob('/lib/systemd/system/php*-fpm.service')?:[] as $file)if(preg_match('/\Aphp[0-9]+\.[0-9]+-fpm\.service\z/D',basename($file)))$units[]=basename($file);
        return array_values(array_unique($units));
    }
    private function unitStates(): array
    {
        $relations = ['RequiredBy', 'RequisiteOf', 'BoundBy', 'ConsistsOf', 'PropagatesStopTo'];
        $properties = array_merge(['Id', 'LoadState', 'ActiveState', 'UnitFileState'], $relations);
        $result = $this->run(array_merge(
            ['/bin/systemctl', 'show', '--all', '--no-pager', '--property='.implode(',', $properties), '--'],
            $this->units()
        ));
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($result['out'])) as $block) {
            $values = [];
            foreach (explode("\n", $block) as $line) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) $values[$parts[0]] = $parts[1];
            }
            if (empty($values['Id'])) continue;

            $dependents = [];
            foreach ($relations as $relation) {
                foreach (preg_split('/\s+/', trim($values[$relation] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $unit) {
                    if ($unit !== $values['Id']) $dependents[$unit] = true;
                }
            }
            $names = array_keys($dependents);
            sort($names, SORT_STRING);
            $out[] = [
                'unit' => $values['Id'],
                'state' => $values['ActiveState'] ?? 'unknown',
                'loaded' => ($values['LoadState'] ?? '') === 'loaded',
                'enabled' => in_array($values['UnitFileState'] ?? '', ['enabled', 'enabled-runtime'], true),
                'stop_dependents' => array_diff($relations, array_keys($values)) === [] ? $names : null,
            ];
        }
        return $out;
    }
    private function fingerprints(): array
    {
        $files=[];
        foreach(['/etc/apache2/sites-available/*.conf','/etc/systemd/system/*.service','/etc/systemd/system/*.timer','/etc/systemd/system/*.service.d/*.conf','/etc/cron.d/*','/etc/ssh/sshd_config','/etc/ssh/sshd_config.d/*.conf','/etc/crontab'] as $pattern)foreach(glob($pattern)?:[] as $f){if(count($files)>=1000)break;if(!is_link($f)&&is_file($f)&&filesize($f)<=1048576)$files[$f]=hash_file('sha256',$f);}
        ksort($files);return $files;
    }
    public function snapshot(array $params=[]): array
    {
        $lock=$this->store->lock('server-monitor');
        try{
            $now=time();$old=$this->store->read('server-monitor');
            if(($old['time']??0)>$now-55)return $this->publicSnapshot($old);
            $line=explode("\n",(string)@file_get_contents('/proc/stat'))[0];$cpu=array_map('intval',array_slice(preg_split('/\s+/',trim($line)),1,8));
            $memory=self::memory((string)@file_get_contents('/proc/meminfo'));$usage=self::cpu($old['cpu_ticks']??[],$cpu);
            $disks=[];foreach(array_unique(['/',$this->cfg['www_root']??'/var/www',$this->store->root()]) as $path){$total=@disk_total_space($path);$free=@disk_free_space($path);$disks[]=['path'=>$path,'used'=>$total>0?round(100*(1-$free/$total),1):null,'free'=>$free?:0];}
            $checks=[];$add=static function(string $key,bool $ok,string $message)use(&$checks){$checks[]=['key'=>$key,'ok'=>$ok,'message'=>$message];};
            $streak=$old['streak']??[];foreach(['cpu'=>$usage,'memory'=>$memory] as $key=>$value){$streak[$key]=$value!==null&&$value>=90?($streak[$key]??0)+1:0;$add($key,$value!==null&&$streak[$key]<3,($key==='cpu'?'CPUの使用率':'メモリの使用率').': '.($value===null?'測定できません':$value.'%').($streak[$key]>=3?'。高い状態が続いています。負荷の高いサイトを確認してください。':''));}
            foreach($disks as $i=>$d)$add('disk.'.$i,$d['used']!==null&&$d['used']<90&&$d['free']>=1073741824,$d['path'].' の空き容量: '.round($d['free']/1073741824,1).'GB。不要な保存データやバックアップを確認してください。');
            $units=$this->unitStates();$add('service.read',count($units)>0,'主要サービスの状態'.($units?'を取得しました':'を取得できません。systemdを確認してください。'));
            foreach($units as $u){if(!$u['loaded']){if(in_array($u['unit'],['apache2.service','php'.\Pjas\Shared\PhpRuntime::controlVersion().'-fpm.service','pjas-agentd.service','pjas-control-web.service','pjas-monitor.timer'],true))$add('service.'.$u['unit'],false,$u['unit'].' が見つかりません。初回準備を確認してください。');continue;}if(!$u['enabled']&&$u['state']==='inactive'&&!in_array($u['unit'],['apache2.service','php'.\Pjas\Shared\PhpRuntime::controlVersion().'-fpm.service','pjas-agentd.service','pjas-control-web.service','pjas-monitor.timer'],true))continue;$add('service.'.$u['unit'],in_array($u['state'],['active','activating'],true),$u['unit'].' の状態: '.$u['state'].'。停止している場合はサービスの記録を確認してください。');}
            // Reuse the local APT cache; never perform updates or network refreshes here.
            $cacheFiles=array_merge(glob('/var/lib/apt/lists/*_InRelease')?:[],glob('/var/lib/apt/lists/*_Release')?:[]);$cacheTime=0;foreach($cacheFiles as $f)$cacheTime=max($cacheTime,(int)@filemtime($f));
            $updates=$old['updates']??[];if(($updates['time']??0)<$now-3600){$r=$this->run(['/usr/bin/apt','list','--upgradable'],8);$updates=['time'=>$now,'ok'=>$r['code']===0,'count'=>0,'security'=>0,'cache_time'=>$cacheTime];if($updates['ok'])foreach(explode("\n",$r['out']) as $l)if(str_contains($l,'[upgradable from:')){$updates['count']++;if(str_contains($l,'-security'))$updates['security']++;}}
            $add('updates',$updates['ok']&&$updates['security']===0,'セキュリティ更新: '.($updates['ok']?$updates['security'].'件':'確認できません').'。サーバーで更新内容を確認してください。');
            $add('updates.fresh',($updates['cache_time']??0)>$now-172800,'更新一覧'.(($updates['cache_time']??0)>$now-172800?'は取得済みです':'が古いか未取得です。sudo apt update で確認してください。'));
            $reboot=is_file('/var/run/reboot-required');$add('reboot',!$reboot,$reboot?'更新の適用にサーバーの再起動が必要です。作業時間を決めて実施してください。':'再起動は要求されていません。');
            // Only aggregate SSH failures. Never return usernames, IPs or journal messages.
            $r=$this->run(['/usr/bin/journalctl','--no-pager','--since=-10 minutes','--unit=ssh.service','--unit=sshd.service','--output=cat','--lines=2000']);
            preg_match_all('/^.*(?:Failed password|Failed publickey|Invalid user|authentication failure).*$/m',$r['out'],$m);$ssh=$r['code']===0?count($m[0]):null;
            $add('ssh',$ssh!==null&&$ssh<20,'直近10分のSSH認証失敗: '.($ssh===null?'確認できません':$ssh.'件（最大2000行を集計）').($ssh!==null&&$ssh>=20?'。SSHの公開範囲と鍵認証を確認してください。':''));
            $files=$this->fingerprints();$changes=$old['changes']??[];
            if(isset($old['fingerprints']))foreach(array_unique(array_merge(array_keys($files),array_keys($old['fingerprints']))) as $f)if(($files[$f]??null)!==($old['fingerprints'][$f]??null))$changes[]=['time'=>$now,'file'=>$f,'type'=>!isset($files[$f])?'削除':(!isset($old['fingerprints'][$f])?'追加':'変更')];
            $changes=array_slice($changes,-100);$recent=count(array_filter($changes,static fn($c)=>$c['time']>$now-600));$add('configuration',$recent===0,'直近10分の設定変更: '.$recent.'件。予定した変更か操作の記録と照合してください。');
            $history=$old['history']??[];$history[]=['time'=>$now,'cpu'=>$usage,'memory'=>$memory,'disk'=>$disks[0]['used']];$history=array_values(array_filter($history,static fn($v)=>$v['time']>$now-86400));
            $new=['time'=>$now,'cpu'=>$usage,'cpu_ticks'=>$cpu,'memory'=>$memory,'uptime'=>(int)explode(' ',(string)@file_get_contents('/proc/uptime'))[0],'disks'=>$disks,'units'=>$units,'updates'=>$updates,'reboot'=>$reboot,'ssh_failures'=>$ssh,'checks'=>$checks,'changes'=>$changes,'fingerprints'=>$files,'streak'=>$streak,'history'=>array_slice($history,-1440)];
            $this->store->write('server-monitor',$new);return $this->publicSnapshot($new);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function publicSnapshot(array $s): array {unset($s['fingerprints'],$s['cpu_ticks'],$s['streak']);return $s;}
    public function restart(array $params): array
    {
        $unit=(string)($params['unit']??'');
        if($unit!=='apache2.service'&&!preg_match('/\Aphp[0-9]+\.[0-9]+-fpm\.service\z/D',$unit))throw new OpException('再起動できるのはApacheとPHPだけです');
        if(!in_array($unit,$this->units(),true))throw new OpException('このサーバーに登録されたサービスを選んでください');
        if($unit==='apache2.service')$this->must(['/usr/sbin/apachectl','configtest'],15);
        else{preg_match('/php([0-9]+\.[0-9]+)-fpm/',$unit,$m);$this->must(['/usr/sbin/php-fpm'.$m[1],'-t'],15);}
        $controlPhp='php'.\Pjas\Shared\PhpRuntime::controlVersion().'-fpm.service';
        $restoreControl=$unit===$controlPhp && $this->run(['/bin/systemctl','is-active','pjas-control-web.service'])['code']===0;
        $this->must(['/bin/systemctl','restart',$unit],30);
        if($restoreControl){$this->must(['/bin/systemctl','start','pjas-control-web.service'],30);$this->must(['/bin/systemctl','is-active','pjas-control-web.service'],10);}
        return ['restarted'=>$unit,'control_http_checked'=>$restoreControl];
    }
}
