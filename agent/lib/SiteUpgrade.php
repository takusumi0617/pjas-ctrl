<?php
declare(strict_types=1);
namespace Pjas\Agentd;
use Pjas\Agentd\Ops\{Components,Isolation,Vhost,ScheduledTask,Health};
use Pjas\Shared\PhpRuntime;

/** Root CLI transaction. Private journals contain unit definitions; public output never does. */
final class SiteUpgrade
{
    private ControlStore $store;
    public function __construct(private array $cfg,private \Closure $database,private ?\Closure $operations=null){$this->store=new ControlStore($cfg);}
    private function id(string $id): string {if(!preg_match('/\A[a-f0-9]{24}\z/D',$id))throw new OpException('移行IDが不正です');return $id;}
    private function load(string $id): array {$j=$this->store->read('site-upgrade-'.$this->id($id));if(!$j)throw new OpException('移行IDが見つかりません');return $j;}
    private function save(array $j): void {$this->store->write('site-upgrade-'.$j['id'],$j);}
    private function registryPath(): string {return ($this->cfg['daemon_dir']??'/var/lib/pjas-daemons').'/registry.json';}
    private function registry(): array {$p=$this->registryPath();if(is_link($p))throw new OpException('デーモン台帳がリンクです');return is_file($p)?json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR):[];}
    private function put(string $path,?string $body,int $mode=0644,?int $uid=null,?int $gid=null): void
    {
        if(is_link($path)||is_link(dirname($path)))throw new OpException('設定先がリンクです');
        if($body===null){if(is_file($path)&&!unlink($path))throw new OpException('設定を削除できません');return;}
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true))throw new OpException('設定フォルダを作成できません');
        $temp=$path.'.tmp-'.bin2hex(random_bytes(8));
        try{if(file_put_contents($temp,$body)===false||!chmod($temp,$mode))throw new OpException('設定を保存できません');
            if($uid!==null&&!chown($temp,$uid))throw new OpException('所有者を設定できません');
            if($gid!==null&&!chgrp($temp,$gid))throw new OpException('グループを設定できません');
            if(!rename($temp,$path))throw new OpException('設定を配置できません');
        }finally{if(is_file($temp))unlink($temp);}
    }
    private function snapshot(string $path): array
    {
        if(is_link($path))throw new OpException('対象設定がリンクです: '.$path);
        if(!file_exists($path))return ['path'=>$path,'body'=>null];
        $st=stat($path);if(!is_file($path)||$st['size']>2097152)throw new OpException('設定ファイルの種類・サイズを確認してください');
        return ['path'=>$path,'body'=>file_get_contents($path),'mode'=>$st['mode']&0777,'uid'=>$st['uid'],'gid'=>$st['gid']];
    }
    private function restore(array $files): void {foreach($files as $f)$this->put($f['path'],$f['body'],$f['mode']??0644,$f['uid']??null,$f['gid']??null);}
    private function sys(array $args): string {return $this->op('systemctl',$args);}
    private function op(string $kind,array $args): mixed
    {
        if($this->operations)return ($this->operations)($kind,$args);
        $cfg=$args['cfg']??$this->cfg;$paths=new Paths($cfg['www_root']);
        return match($kind){
            'systemctl'=>Proc::must(array_merge([$this->cfg['systemctl']],$args),null,60),
            'pause'=>(new Vhost($cfg,$paths))->pause(['service'=>$args['spec'],'action'=>$args['action'],'code'=>503,'page_mode'=>'default']),
            'isolate'=>(new Isolation($cfg))->apply(['service'=>$args['spec'],'runtime'=>$args['runtime'],'confirm'=>true,'standardize'=>true]),
            'pause-state'=>$this->pauseState($cfg,$args['spec'],$args['state']),
            'scope'=>(new SiteIsolation($cfg))->scope($args['name']),
            'acl-save'=>$this->aclSave($args['path'],$args['file']),
            'acl-restore'=>Proc::must(['/usr/bin/setfacl','--restore='.$args['file']],null,600),
            'cron'=>(new ScheduledTask($cfg,$paths))->apply(['task'=>$args['task']]),
            'health'=>(new Health($cfg))->check(['service'=>$args['spec']]),
            'preflight'=>$this->preflight($args['runtime'],$args['name']),
            'drain'=>$this->drain($args['name'],$args['scripts']),
            default=>throw new OpException('未定義の移行操作です'),
        };
    }
    private function pauseState(array $cfg,array $spec,array $state): array
    {
        (new SitePause($cfg))->write($spec['name'],$state);
        return (new Vhost($cfg,new Paths($cfg['www_root'])))->apply(['service'=>$spec]);
    }
    private function preflight(string $runtime,string $name): array
    {
        PhpRuntime::requireInstalled($runtime);
        Proc::must(['/usr/bin/python3',__DIR__.'/isolate_tree.py','--check',$this->cfg['www_root'].'/'.Spec::name($name)],null,600);
        return [];
    }
    private function aclSave(string $path,string $file): array
    {
        $r=Proc::run(['/usr/bin/getfacl','-R','-P','--absolute-names',$path],null,600,null,[],null,$file);
        if($r['code']!==0)throw new OpException('所有者・ACLを退避できません');chmod($file,0600);return [];
    }
    private function state(string $unit): array
    {
        $text=$this->sys(['show','--no-pager','--property=LoadState,ActiveState,UnitFileState,User,Group,SupplementaryGroups,AmbientCapabilities,CapabilityBoundingSet,NoNewPrivileges','--',$unit]);$out=[];
        foreach(explode("\n",$text) as $line)if(str_contains($line,'=')){[$k,$v]=explode('=',$line,2);$out[$k]=$v;}
        return $out;
    }
    /** Conditions exposes evaluation state on some systemd versions, not loaded configuration. */
    public static function hasStartHold(string $text,string $path): bool
    {
        $text=preg_replace('/\\\\\r?\n[ \t]*/',' ',$text);$section='';$held=false;
        foreach(preg_split('/\R/',$text) as $line){
            $line=trim($line);if($line===''||str_starts_with($line,'#')||str_starts_with($line,';'))continue;
            if(preg_match('/^\[([^\]]+)\]$/',$line,$m)){$section=$m[1];continue;}
            if($section!=='Unit'||!str_contains($line,'='))continue;
            [$key,$value]=array_map('trim',explode('=',$line,2));
            if(!str_starts_with($key,'Condition'))continue;
            // An empty assignment to any Condition*= resets the entire condition list.
            if($value==='')$held=false;
            elseif($key==='ConditionPathExists'&&$value===$path)$held=true;
        }
        return $held;
    }
    private function fingerprint(array $bundle): string
    {
        $tasks=array_map(fn($t)=>array_intersect_key($t,array_flip(['id','name','schedule','command','enabled'])),$bundle['tasks']);
        return hash('sha256',json_encode([$bundle['spec'],$tasks,(int)$bundle['busy']],JSON_THROW_ON_ERROR));
    }
    private function command(string $text,string $runtime): string
    {
        if($runtime!=='static'){$text=preg_replace('~^/usr/bin/env php(?:8\.[0-9]+)?(?=\s|$)~m',PhpRuntime::cli($runtime),$text);$text=preg_replace('~^"/usr/bin/php(?:8\.[0-9]+)?"~m','"'.PhpRuntime::cli($runtime).'"',$text);}
        $text=PhpRuntime::command($text,$runtime);
        if($runtime!=='static')$text=preg_replace('~^#!/usr/bin/php(?:8\.[0-9]+)?$~m','#!'.PhpRuntime::cli($runtime),$text);
        if(preg_match_all('~(?<![A-Za-z0-9])php(?:-fpm)?(8\.[0-9]+)\b~',$text,$m))foreach($m[1] as $v)if($runtime==='static'||$v!==PhpRuntime::version($runtime))throw new OpException('複雑なコマンド内に旧PHP指定が残ります。計画前に実行定義を整理してください');
        if(preg_match('~(?<![A-Za-z0-9_./-])(?:/usr/bin/)?php(?=\s|$)~',$text))throw new OpException('間接的なコマンド内にバージョン未指定のPHPが残ります。実行定義を明示してください');
        return $text;
    }
    public function plan(string $name,string $runtime): array
    {
        $name=Spec::name($name);if(!in_array($runtime,PhpRuntime::RUNTIMES,true))throw new OpException('PHPランタイムが不正です');
        if(Components::protectedSite($this->cfg,$name))throw new OpException('v2サイトは専用のコンポーネント手順を使用してください');
        if($this->store->read('upgrade-active-'.$name))throw new OpException('未完了の移行があります。statusで確認してください');
        $bundle=($this->database)('read',$name,[]);$spec=Spec::service($bundle['spec']);
        if($bundle['busy'])throw new OpException('実行待ち・実行中の作業があります');
        $paths=new Paths($this->cfg['www_root']);$tag=$paths->currentTag($name);
        if(!$tag)throw new OpException('先に既存サイトの取り込みを完了してください');
        $release=$paths->release($name,$tag);if(is_link($paths->base($name))||is_link($release)||!is_dir($release)||$paths->assertInside($name,$paths->current($name))!==realpath($release))throw new OpException('公開先が正規のリリースではありません');
        $id=bin2hex(random_bytes(12));$files=[];$workers=[];$registry=$this->registry();
        $oldState=(new SiteIsolation($this->cfg))->state($name);$oldUser=$oldState?SiteIsolation::identity($name):$this->cfg['web_user'];
        $unitDir=$this->cfg['daemon_unit_dir']??'/etc/systemd/system';
        foreach($registry as $unit=>$row){
            if(($row['service']??null)!==$name)continue;
            if(!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.@-]*\.service\z/D',$unit)||ComponentPolicy::reservedUnit($this->cfg,$unit)||$unit==='pjas-agentd.service')throw new OpException('対象外の常駐unitです');
            $text=$this->sys(['cat','--no-pager','--',$unit]);$info=DaemonDefinition::parse($text,$oldUser);$state=$this->state($unit);
            if(preg_match('/^\s*PermissionsStartOnly\s*=\s*(?:yes|true|1)\s*$/mi',$text))throw new OpException('rootで付随処理を実行するunitは自動移行できません: '.$unit);
            if(!$info['editable']||($state['LoadState']??'')!=='loaded'||!in_array($state['ActiveState']??'',['active','inactive','failed'],true)||!in_array($state['UnitFileState']??'',['enabled','disabled','static'],true))throw new OpException('自動移行できないunitです: '.$unit);
            $base=$this->cfg['www_root'].'/'.$name;$cwd=realpath($info['working_directory']);
            if(!$cwd||!str_starts_with($cwd.'/',realpath($base).'/'))throw new OpException('サイト外のWorkingDirectoryです: '.$unit);
            $override="[Service]\nUser=".SiteIsolation::identity($name)."\nGroup=".SiteIsolation::identity($name)."\nSupplementaryGroups=\nAmbientCapabilities=\nCapabilityBoundingSet=\nUMask=0077\nNoNewPrivileges=yes\nRestrictSUIDSGID=yes\n";
            if(empty($row['managed']))$override.="ExecStart=\nExecStart=".$this->command($info['exec_start'],$runtime)."\n";
            foreach($info['path_settings'] as $key=>$values)if(in_array($key,['ExecStartPre','ExecStartPost','ExecStop','ExecStopPost'],true)){
                $override.=$key."=\n";foreach($values as $value){if(preg_match('/^[+!@]/',$value))throw new OpException('特権付きの付随コマンドは自動移行できません');$override.=$key.'='.$this->command($value,$runtime)."\n";}
            }
            $path=$unitDir.'/'.$unit.'.d/zzzzzz-pjas-isolation.conf';$hold=$unitDir.'/'.$unit.'.d/zzzzzz-pjas-upgrade-hold.conf';
            $files[]=$this->snapshot($path);$files[]=$this->snapshot($hold);$script=null;
            if(!empty($row['managed'])){$script=($this->cfg['daemon_dir']??'/var/lib/pjas-daemons').'/'.$unit.'.sh';$snap=$this->snapshot($script);if($snap['body']===null)throw new OpException('常駐スクリプトがありません');$files[]=$snap;$script=['path'=>$script,'body'=>$this->command($snap['body'],$runtime)];}
            $workers[$unit]=['row'=>$row,'definition'=>$text,'state'=>$state,'override'=>$override,'path'=>$path,'hold'=>$hold,'script'=>$script];
        }
        foreach($bundle['tasks'] as $task){
            $this->command($task['command'],$runtime);
            foreach(['cron_dir'=>'/etc/cron.d','cron_script_dir'=>'/var/lib/pjas/cron'] as $key=>$default)$files[]=$this->snapshot(($this->cfg[$key]??$default).($key==='cron_dir'?'/pjas-task-':'/task-').$task['id'].($key==='cron_dir'?'':'.sh'));
        }
        $logAttrs=[];foreach($bundle['tasks'] as $task){$p=($this->cfg['cron_log_dir']??'/var/log/pjas/cron').'/task-'.$task['id'].'.log';if(is_link($p))throw new OpException('cronログがリンクです');if(is_file($p)){$st=stat($p);$logAttrs[]=['path'=>$p,'uid'=>$st['uid'],'gid'=>$st['gid'],'mode'=>$st['mode']&0777];}}
        // Refuse orphan cron scripts rather than silently leaving a legacy scheduler active.
        $known=array_column($bundle['tasks'],'id');
        foreach(glob(($this->cfg['cron_script_dir']??'/var/lib/pjas/cron').'/task-*.sh')?:[] as $p)if(str_contains((string)file_get_contents($p),$this->cfg['www_root'].'/'.$name.'/')&&!in_array((int)substr(basename($p),5,-3),$known))throw new OpException('DBにないcronスクリプトがあります: '.$p);
        $j=['id'=>$id,'name'=>$name,'runtime'=>$runtime,'phase'=>'planned','bundle'=>$bundle,'fingerprint'=>$this->fingerprint($bundle),'workers'=>$workers,'files'=>$files,'pause'=>(new SitePause($this->cfg))->read($name),'old_isolation'=>$oldState,'log_attrs'=>$logAttrs,'current_tag'=>(new Paths($this->cfg['www_root']))->currentTag($name),'created_at'=>gmdate('c')];
        $this->save($j);return $this->summary($j);
    }
    private function summary(array $j): array
    {
        return ['id'=>$j['id'],'site'=>$j['name'],'phase'=>$j['phase'],'source'=>$j['bundle']['spec']['runtime'],'target'=>$j['runtime'],'standardizes_apache'=>true,'restarts_shared_fpm'=>$j['bundle']['spec']['runtime']!=='static','workers'=>array_keys($j['workers']),'cron_ids'=>array_column($j['bundle']['tasks'],'id'),'paused'=>!empty((new SitePause($this->cfg))->read($j['name'])['enabled']),'last_error'=>$j['error']??null,'journal'=>$this->store->root().'/site-upgrade-'.$j['id'].'.json'];
    }
    public function status(string $id): array {if(!preg_match('/\A[a-f0-9]{24}\z/D',$id)){$pending=$this->store->read('upgrade-active-'.Spec::name($id));$id=$pending['id']??throw new OpException('このサイトに進行中の移行はありません');}return $this->summary($this->load($id));}
    private function locked(string $id,\Closure $fn): array
    {
        $j=$this->load($id);$site=$j['name'];$locks=[];
        try{
            foreach([$this->cfg['apache_sites'].'/.pjas-deploy-'.$site.'.lock',($this->cfg['daemon_dir']??'/var/lib/pjas-daemons').'/lock'] as $p){if(is_link($p))throw new OpException('ロックがリンクです');if(!is_dir(dirname($p)))mkdir(dirname($p),0755,true);$fp=fopen($p,'c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))throw new OpException('サイトまたはデーモンの作業中です');$locks[]=$fp;}
            $active=$this->store->read('upgrade-active-'.$site);if($active&&($active['id']??'')!==$id)throw new OpException('別の移行が進行中です');
            return $fn($j);
        }finally{foreach(array_reverse($locks) as $fp){flock($fp,LOCK_UN);fclose($fp);}}
    }
    private function context(array $j): array {return array_replace($this->cfg,['site_upgrade_id'=>$j['id']]);}
    private function changeDb(array $j,string $mode): void {($this->database)('write',$j['name'],['original'=>$j['bundle'],'runtime'=>$j['runtime'],'mode'=>$mode]);}
    private function taskPaths(array $j): array
    {
        $out=[];foreach($j['bundle']['tasks'] as $t){$out[]=($this->cfg['cron_dir']??'/etc/cron.d').'/pjas-task-'.$t['id'];$out[]=($this->cfg['cron_script_dir']??'/var/lib/pjas/cron').'/task-'.$t['id'].'.sh';}return $out;
    }
    public function apply(string $id,bool $standardize,bool $externalStopped): array
    {
        if(!$standardize||!$externalStopped)throw new OpException('--accept-standard-apache と --external-processes-stopped が必要です');
        return $this->locked($id,function(array $j):array{
            if($j['phase']==='active'){$this->store->write('upgrade-active-'.$j['name'],[]);return $this->summary($j);}
            if($j['phase']==='prepared')return $this->summary($j);
            if($j['phase']==='activating')throw new OpException('再開処理が中断しました。同じIDでactivateを再実行してください');
            if(in_array($j['phase'],['isolating','rolling-back','rolled-back'],true))throw new OpException('中断した隔離処理は再実行せず、rollbackで設定を復旧してください');
            $ctx=$this->context($j);$spec=Spec::service($j['bundle']['spec']);
            try{
                if($j['phase']==='planned'){
                    $this->op('preflight',['runtime'=>$j['runtime'],'name'=>$j['name']]);
                    if((new Paths($this->cfg['www_root']))->currentTag($j['name'])!==$j['current_tag'])throw new OpException('公開リリースが計画時から変更されました');
                    $paths=new Paths($this->cfg['www_root']);$release=$paths->release($j['name'],$j['current_tag']);if(is_link($release)||$paths->assertInside($j['name'],$paths->current($j['name']))!==realpath($release))throw new OpException('公開先の参照が計画時から変更されました');
                    if($this->fingerprint(($this->database)('read',$j['name'],[]))!==$j['fingerprint'])throw new OpException('DBが計画時から変更されました。新しい計画を作成してください');
                    $selected=array_filter($this->registry(),fn($r)=>($r['service']??'')===$j['name']);$expected=array_map(fn($w)=>$w['row'],$j['workers']);if($selected!=$expected)throw new OpException('常駐処理の関連付けが計画時から変更されました');
                    foreach($j['files'] as $f)if($this->snapshot($f['path'])!=$f)throw new OpException('設定が計画時から変更されました');
                    foreach($j['workers'] as $unit=>$w)if($this->sys(['cat','--no-pager','--',$unit])!==$w['definition']||$this->state($unit)!=$w['state'])throw new OpException('unitが計画時から変更されました');
                    $core=[];$core[]=$this->snapshot($this->cfg['apache_sites'].'/pjas-'.$j['name'].'.conf');$core[]=$this->snapshot($this->cfg['apache_sites'].'/.pjas-inherited-'.$j['name'].'.json');
                    foreach(array_unique([$spec['runtime'],$j['runtime']]) as $runtime)if($runtime!=='static')$core[]=$this->snapshot('/etc/php/'.PhpRuntime::version($runtime).'/fpm/pool.d/pjas-'.$j['name'].'.conf');
                    $j['core']=$core;$j['acl']=$this->store->directory('site-upgrades').'/'.$j['id'].'.acl';
                    $j['phase']='quiescing';$this->save($j);$this->store->write('upgrade-active-'.$j['name'],['id'=>$j['id']]);
                }
                if($j['phase']==='quiescing'){
                    $this->store->write('upgrade-active-'.$j['name'],['id'=>$j['id']]);
                    foreach($j['workers'] as $unit=>$w)$this->put($w['hold'],"[Unit]\nConditionPathExists=".$this->store->root().'/never-start-'.$j['id']."\n");
                    $this->sys(['daemon-reload']);
                    foreach($j['workers'] as $unit=>$w){
                        $dropIns=preg_split('/\s+/',trim($this->sys(['show','--value','--property=DropInPaths','--',$unit])),-1,PREG_SPLIT_NO_EMPTY);
                        $definition=$this->sys(['cat','--no-pager','--',$unit]);
                        if(!in_array($w['hold'],$dropIns,true)||!self::hasStartHold($definition,$this->store->root().'/never-start-'.$j['id']))throw new OpException('unitの起動禁止設定が読み込まれていないか、後続の設定で解除されています');
                    }
                    $oldCfg=$j['old_isolation']?$this->op('scope',['cfg'=>$ctx,'name'=>$j['name']]):$ctx;
                    $this->op('pause',['cfg'=>$oldCfg,'spec'=>$spec,'action'=>'pause']);
                    $this->changeDb($j,'quiesce');foreach($this->taskPaths($j) as $p)$this->put($p,null);
                    foreach($j['workers'] as $unit=>$w){$this->sys(['stop','--',$unit]);if($w['state']['UnitFileState']==='enabled')$this->sys(['disable','--',$unit]);}
                    $this->op('drain',['name'=>$j['name'],'scripts'=>$this->taskPaths($j)]);
                    $rows=$this->registry();foreach($j['workers'] as $unit=>$w){if(isset($rows[$unit])&&$rows[$unit]!=$w['row'])throw new OpException('デーモン台帳が変更されました');unset($rows[$unit]);}$this->put($this->registryPath(),json_encode($rows,JSON_THROW_ON_ERROR));
                    if($spec['runtime']!=='static')$this->sys(['restart',$spec['runtime']]);
                    $this->op('acl-save',['path'=>$this->cfg['www_root'].'/'.$j['name'],'file'=>$j['acl']]);$j['acl_ready']=true;
                    $j['phase']='isolating';$this->save($j);
                    $j['result']=$this->op('isolate',['cfg'=>$ctx+['isolation_fpm_drained'=>true],'spec'=>$spec,'runtime'=>$j['runtime']]);$j['phase']='isolated';$this->save($j);
                }
                if($j['phase']==='isolated'){
                    $this->changeDb($j,'migrated');
                    foreach($j['workers'] as $unit=>$w){$this->put($w['path'],$w['override']);if($w['script'])$this->put($w['script']['path'],$w['script']['body']);}
                    $rows=$this->registry();foreach($j['workers'] as $unit=>$w){if(isset($rows[$unit])&&$rows[$unit]!=$w['row'])throw new OpException('同名unitが他の処理に登録されました');$row=$w['row'];$row['follow_paths']=true;foreach(['command','exec_start'] as $key)if(isset($row[$key]))$row[$key]=$this->command($row[$key],$j['runtime']);$rows[$unit]=$row;}$this->put($this->registryPath(),json_encode($rows,JSON_THROW_ON_ERROR));
                    $this->sys(['daemon-reload']);$j['phase']='prepared';unset($j['error']);$this->save($j);
                }
                return $this->summary($j);
            }catch(\Throwable $e){$j['error']='処理を停止しました。statusとroot専用journalを確認し、applyの再開またはrollbackを実行してください';$this->save($j);throw new OpException($j['error'].' ID: '.$j['id'].' / '.$e->getMessage());}
        });
    }
    public function activate(string $id,bool $confirm): array
    {
        if(!$confirm)throw new OpException('--confirm-activation が必要です');
        return $this->locked($id,function(array $j):array{
            if($j['phase']==='active'){$this->store->write('upgrade-active-'.$j['name'],[]);return $this->summary($j);}
            if(!in_array($j['phase'],['prepared','activating'],true))throw new OpException('先にapplyを完了してください');
            $cfg=$this->op('scope',['cfg'=>$this->context($j),'name'=>$j['name']]);$spec=array_replace(Spec::service($j['bundle']['spec']),['runtime'=>$j['runtime'],'extra_conf'=>'','allow_override'=>'None']);
            $j['phase']='activating';$this->save($j);
            try{
                foreach($j['workers'] as $unit=>$w)$this->put($w['hold'],null);$this->sys(['daemon-reload']);
                foreach($j['workers'] as $unit=>$w){$state=$this->state($unit);if(($state['User']??'')!==SiteIsolation::identity($j['name'])||($state['Group']??'')!==SiteIsolation::identity($j['name'])||($state['SupplementaryGroups']??'')!==''||($state['AmbientCapabilities']??'')!==''||($state['CapabilityBoundingSet']??'')!==''||($state['NoNewPrivileges']??'')!=='yes')throw new OpException('実効User/Group・補助グループ・権限制限が移行設定と一致しません');if($w['state']['UnitFileState']==='enabled')$this->sys(['enable','--',$unit]);if($w['state']['ActiveState']==='active'){$this->sys(['start','--',$unit]);if(($this->state($unit)['ActiveState']??'')!=='active')throw new OpException('常駐処理が起動しません');}}
                if(!empty($j['pause']['enabled']))$this->op('pause-state',['cfg'=>$cfg,'spec'=>$spec,'state'=>$j['pause']]);
                if(empty($j['pause']['enabled'])){$this->op('pause',['cfg'=>$cfg,'spec'=>$spec,'action'=>'resume']);$health=$this->op('health',['cfg'=>$cfg,'spec'=>$spec]);if(empty($health['ok'])||!empty($health['paused']))throw new OpException('公開後のHTTP検査に失敗しました');}
                foreach($j['bundle']['tasks'] as $task){$task['command']=$this->command($task['command'],$j['runtime']);$this->op('cron',['cfg'=>$cfg,'task'=>$task]);}
                $this->changeDb($j,'activate');$j['phase']='active';unset($j['error']);$this->save($j);$this->store->write('upgrade-active-'.$j['name'],[]);return $this->summary($j);
            }catch(\Throwable $e){
                $failures=[];
                try{$this->op('pause',['cfg'=>$cfg,'spec'=>$spec,'action'=>'pause']);}catch(\Throwable $stop){$failures[]='サイト停止';}
                foreach($this->taskPaths($j) as $p)try{$this->put($p,null);}catch(\Throwable $stop){$failures[]='cron停止';}
                foreach($j['workers'] as $unit=>$w){
                    try{$this->put($w['hold'],"[Unit]\nConditionPathExists=".$this->store->root().'/never-start-'.$j['id']."\n");$this->sys(['stop','--',$unit]);if($w['state']['UnitFileState']==='enabled')$this->sys(['disable','--',$unit]);}catch(\Throwable $stop){$failures[]='unit停止: '.$unit;}
                }
                try{$this->sys(['daemon-reload']);}catch(\Throwable $stop){$failures[]='daemon-reload';}
                try{$this->changeDb($j,'hold');}catch(\Throwable $stop){$failures[]='DBのcron無効化';}
                $j['phase']='prepared';$j['error']=$failures?'再開に失敗し、一部の停止処理も失敗しました: '.implode(',',$failures):'再開に失敗しました。停止状態を維持しています';
                $this->save($j);throw new OpException($j['error'].' / '.$e->getMessage());
            }
        });
    }
    public function rollback(string $id,bool $confirm): array
    {
        if(!$confirm)throw new OpException('--confirm-rollback が必要です');
        return $this->locked($id,function(array $j):array{
            if($j['phase']==='rolled-back'){$this->store->write('upgrade-active-'.$j['name'],[]);return $this->summary($j);}
            if(in_array($j['phase'],['planned','active'],true))throw new OpException('未適用または公開済みの移行は自動rollbackの対象外です');
            $j['phase']='rolling-back';$this->save($j);
            foreach($j['workers'] as $unit=>$w)$this->sys(['stop','--',$unit]);
            foreach($this->taskPaths($j) as $p)$this->put($p,null);
            $this->restore($j['core']);if(!empty($j['acl_ready']))$this->op('acl-restore',['file'=>$j['acl']]);
            $this->store->write('isolation-'.$j['name'],$j['old_isolation']);
            $this->restore($j['files']);foreach($j['log_attrs']??[] as $f)if(is_file($f['path'])&&!is_link($f['path'])){chown($f['path'],$f['uid']);chgrp($f['path'],$f['gid']);chmod($f['path'],$f['mode']);}$rows=$this->registry();foreach($j['workers'] as $unit=>$w){if(isset($rows[$unit])&&($rows[$unit]['service']??'')!==$j['name'])throw new OpException('unitが別サイトに移動されています');$rows[$unit]=$w['row'];}$this->put($this->registryPath(),json_encode($rows,JSON_THROW_ON_ERROR));
            $this->changeDb($j,'rollback');$this->sys(['daemon-reload']);
            foreach(array_unique([$j['bundle']['spec']['runtime'],$j['runtime']]) as $runtime)if($runtime!=='static')$this->sys(['reload-or-restart',$runtime]);
            $cfg=$j['old_isolation']?$this->op('scope',['cfg'=>$this->context($j),'name'=>$j['name']]):$this->context($j);
            (new SitePause($cfg))->write($j['name'],$j['pause']);
            Proc::must([$this->cfg['apachectl'],'configtest']);Proc::must([$this->cfg['apachectl'],'graceful']);
            foreach($j['workers'] as $unit=>$w){if($w['state']['UnitFileState']!=='static')$this->sys([$w['state']['UnitFileState']==='enabled'?'enable':'disable','--',$unit]);if($w['state']['ActiveState']==='active')$this->sys(['start','--',$unit]);}
            $j['phase']='rolled-back';unset($j['error']);$this->save($j);$this->store->write('upgrade-active-'.$j['name'],[]);return $this->summary($j);
        });
    }
    /** Wait for already dispatched cron/application processes; never kill another site's UID. */
    private function drain(string $name,array $scripts): array
    {
        $base=realpath($this->cfg['www_root'].'/'.$name);$users=[$this->cfg['web_user'],SiteIsolation::identity($name)];$uids=[];
        foreach($users as $u)if($pw=posix_getpwnam($u))$uids[]=$pw['uid'];
        $pids=[];
        foreach(glob('/proc/[0-9]*',GLOB_ONLYDIR)?:[] as $p){$st=@stat($p);if(!$st||!in_array($st['uid'],$uids,true)||$st['uid']===0)continue;$cwd=@readlink($p.'/cwd');$args=explode("\0",(string)@file_get_contents($p.'/cmdline'));$inside=$cwd&&str_starts_with($cwd.'/',$base.'/');foreach($args as $arg)if(str_starts_with($arg,$base.'/'))$inside=true;if($inside||array_intersect($args,$scripts))$pids[]=basename($p);}
        if($pids)throw new OpException('既に実行中のcron・外部処理が残っています。終了を待ってapplyを再実行してください。PID: '.implode(',',$pids));
        return [];
    }
}
