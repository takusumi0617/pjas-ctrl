<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once dirname(__DIR__,2).'/app/Domain/GitHubRepository.php';
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';
use Pjas\Domain\GitHubRepository;
use Pjas\Shared\PhpRuntime;

/** Self-update authority lives in root configuration; requests only carry an expiring review ID. */
final class ControlUpdate
{
    private ControlStore $store;
    public function __construct(private array $cfg,private $runner=null,private $credentials=null,private int $owner=0){$this->store=new ControlStore($cfg);}
    private function run(array $args,?string $cwd=null,array $env=[],int $timeout=120,?string $user=null,?string $output=null): array
    {
        return $this->runner?($this->runner)($args,$cwd,$env,$timeout,$user,$output):Proc::run($args,$cwd,$timeout,$user,$env,null,$output);
    }
    private function must(array $args,?string $cwd=null,array $env=[],int $timeout=120,?string $user=null,?string $output=null): string
    {
        $r=$this->run($args,$cwd,$env,$timeout,$user,$output);
        if($r['code']!==0){$this->store->write('control-update-command-error',['program'=>basename($args[0]),'code'=>$r['code'],'stderr'=>$r['err'],'stdout'=>$r['out']]);throw new OpException('更新処理のコマンドに失敗しました（'.basename($args[0]).' / exit '.$r['code'].'）');}
        return trim($r['out']);
    }
    private function path(): string{return $this->cfg['control_update_config']??'/etc/pjas-control/update.json';}
    private function safeFile(string $path): void
    {
        $s=lstat($path);if(!$s||is_link($path)||!is_file($path)||$s['uid']!==$this->owner||($s['mode']&0022))throw new OpException('更新用ファイルはroot所有・他ユーザー書き込み禁止にしてください');
    }
    private function safeParents(string $path): void
    {
        for($p=$path;$p!=='/';$p=dirname($p)){if($this->owner!==0&&$p===sys_get_temp_dir())break;$s=lstat($p);if(!$s||is_link($p)||$s['uid']!==$this->owner||($s['mode']&0022))throw new OpException('更新先の親ディレクトリをroot所有・書き込み禁止にしてください');}
    }
    public static function origin(string $url): string { $url=preg_replace('~\A(?:git@github\.com:|ssh://git@github\.com/)~','https://github.com/',trim($url));return GitHubRepository::normalize($url);}
    public function settings(): array
    {
        if(!is_file($this->path()))throw new OpException('サーバーで pjas-control-update install を実行してください');
        $this->safeParents(dirname($this->path()));$this->safeFile($this->path());$s=json_decode(file_get_contents($this->path()),true,512,JSON_THROW_ON_ERROR);
        if(($s['version']??null)!==1||!is_string($s['root']??null)||realpath($s['root'])!==$s['root']||!is_dir($s['root'].'/.git')||is_link($s['root'].'/.git'))throw new OpException('更新先の設定を確認してください');
        $s['repository']=GitHubRepository::normalize($s['repository']??'');
        if(!preg_match('~\A[A-Za-z0-9][A-Za-z0-9_./-]{0,100}\z~D',$s['branch']??'')||str_contains($s['branch'],'..')||str_contains($s['branch'],'//'))throw new OpException('更新ブランチが不正です');
        if(!in_array($s['auth']??'auto',['auto','public','github_app'],true))throw new OpException('更新用認証設定が不正です');
        $s['auth']??='auto';$s['php_version']=PhpRuntime::controlVersion();$this->safeParents($s['root']);return $s;
    }
    private function git(array $args,string $root,array $env=[]): string
    {
        return $this->must(array_merge(['/usr/bin/git','-c','safe.directory='.$root,'-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-c','credential.helper=','-c','http.extraHeader=','-c','http.followRedirects=false'], $args),$root,$env?:GitRepositories::publicEnv(),180);
    }
    private function lock()
    {
        $p=$this->store->directory('locks').'/control-update';if(is_link($p))throw new OpException('更新ロックが不正です');$fp=fopen($p,'c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))throw new OpException('別の更新確認・更新処理が実行中です');return $fp;
    }
    private function busy(): void
    {
        $state=$this->store->read('control-update');if(in_array($state['phase']??'', ['queued','running','recovering'],true)||(($state['phase']??'')==='failed'&&empty($state['rollback_complete'])))throw new OpException('ctrlの更新が進行中です。結果を確認してください');
    }
    private function local(array $s): array
    {
        $root=$s['root'];$this->safeFile($root.'/config.php');$this->safeFile($root.'/agent/config.php');
        // A root-owned checkout must not contain writable files or Git metadata controlled by the web user.
        foreach([$root.'/.git',$root.'/app',$root.'/agent',$root.'/bin',$root.'/public',$root.'/shared',$root.'/views',$root.'/db'] as $dir){
            $this->safeParents($dir);
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $file){
                if($file->isLink()||$file->getOwner()!==$this->owner||($file->getPerms()&0022))throw new OpException('コード・Gitメタデータの所有者と書き込み権限を確認してください');
            }
        }
        if(realpath($this->git(['rev-parse','--show-toplevel'],$root))!==$root)throw new OpException('Gitの作業フォルダと更新先が一致しません');
        $head=$this->git(['rev-parse','HEAD'],$root);if(!preg_match('/\A[a-f0-9]{40}\z/D',$head))throw new OpException('現在のコミットを確認できません');
        if($this->git(['symbolic-ref','--short','HEAD'],$root)!==$s['branch'])throw new OpException('更新先は設定されたブランチのチェックアウトにしてください');
        if(self::origin($this->git(['remote','get-url','origin'],$root))!==$s['repository'])throw new OpException('originとroot管理の更新元が一致しません');
        $dirty=$this->git(['status','--porcelain=v1','--untracked-files=normal'],$root);
        foreach(explode("\n",$dirty) as $line)if($line!==''&&!in_array($line,[' M agent/config.php','M agent/config.php'],true))throw new OpException('コードに未保存・未追跡の変更があります。更新前に整理してください');
        return ['head'=>$head,'web_config'=>hash_file('sha256',$root.'/config.php'),'agent_config'=>hash_file('sha256',$root.'/agent/config.php')];
    }
    public function validateInstallation(): array {return $this->local($this->settings());}
    private function repository(): string
    {
        $path=$this->store->directory('control-updates').'/repository.git';
        if(is_link($path))throw new OpException('更新用リポジトリがリンクです');
        if(!is_dir($path))$this->must(['/usr/bin/git','init','--bare',$path],null,GitRepositories::publicEnv());
        return $path;
    }
    public static function blockers(array $changes,array $tree): array
    {
        $blocked=[];
        foreach($tree as $mode)if(!in_array($mode,['100644','100755'],true))$blocked[]='リンク・サブモジュールを含む更新は画面から適用できません';
        foreach($changes as $file){
            if(in_array($file,['config.php','agent/config.php','app/bootstrap.php','app/Support/Config.php','app/Domain/GitHubRepository.php','agent/lib/ControlStore.php','agent/lib/Proc.php','agent/lib/GitRepositories.php','agent/lib/GitHubApp.php','shared/PhpRuntime.php'],true)||str_starts_with($file,'systemd/')||$file==='bin/pjas-install-features'||$file==='bin/pjas-security-update'||$file==='agent/lib/SecurityUpdate.php'||$file==='agent/lib/ControlUpdate.php'||$file==='bin/pjas-control-update')$blocked[]='root設定・サービス・更新基盤の変更があります。サーバーで内容を照合して更新してください';
        }
        return array_values(array_unique($blocked));
    }
    public function review(array $p=[]): array
    {
        $lock=$this->lock();
        try{
            $this->busy();$s=$this->settings();$local=$this->local($s);$repo=$this->repository();
            $fetch=function(array $env)use($s,$repo):array{return $this->run(['/usr/bin/git','-c','credential.helper=','-c','http.extraHeader=','-c','http.followRedirects=false','-c','core.hooksPath=/dev/null','-c','protocol.allow=never','-c','protocol.https.allow=always','--git-dir='.$repo,'fetch','--no-tags','--no-recurse-submodules','--',$s['repository'],'refs/heads/'.$s['branch']],null,$env,180);};
            $app=new GitHubApp($this->cfg);$auth=$s['auth']==='auto'?(!empty($app->status()['configured'])?'github_app':'public'):$s['auth'];
            if($auth==='github_app'){
                try{$r=$this->credentials?($this->credentials)($s['repository'],$fetch):$app->withCredentials($s['repository'],$fetch);}
                catch(OpException $e){if($s['auth']!=='auto')throw $e;$auth='public';$r=$fetch(GitRepositories::publicEnv());}
                if($r['code']!==0&&$s['auth']==='auto'&&$auth==='github_app'){$auth='public';$r=$fetch(GitRepositories::publicEnv());}
            }else $r=$fetch(GitRepositories::publicEnv());
            if($r['code']!==0)throw new OpException('GitHubから取得できません。Appの許可リポジトリとContents読み取り権限を確認してください');
            $target=$this->git(['--git-dir='.$repo,'rev-parse','FETCH_HEAD'],$s['root']);
            if(!preg_match('/\A[a-f0-9]{40}\z/D',$target))throw new OpException('更新コミットを確認できません');
            $ancestor=$this->run(['/usr/bin/git','--git-dir='.$repo,'merge-base','--is-ancestor',$local['head'],$target],null,GitRepositories::publicEnv());
            if($ancestor['code']!==0)throw new OpException('現在のコードからfast-forwardできません。更新元と独自変更を照合してください');
            $paths=array_values(array_filter(explode("\n",$this->git(['--git-dir='.$repo,'diff','--name-only','--no-renames',$local['head'],$target],$s['root']))));
            $tree=[];foreach(explode("\0",$this->git(['--git-dir='.$repo,'ls-tree','-r','-z',$target],$s['root'])) as $entry)if($entry!=='')$tree[]=substr($entry,0,6);
            $blockers=self::blockers($paths,$tree);
            foreach($paths as $path){$exists=$this->run(['/usr/bin/git','cat-file','-e',$local['head'].':'.$path],$s['root'],GitRepositories::publicEnv());if($exists['code']!==0&&file_exists($s['root'].'/'.$path))$blockers[]='新しいファイルの配置先に既存ファイルがあります';}
            $id=bin2hex(random_bytes(16));$j=['id'=>$id,'base'=>$local['head'],'target'=>$target,'configuration'=>hash('sha256',json_encode($s)),'local'=>$local,'changes'=>$paths,'blockers'=>array_values(array_unique($blockers)),'dependent_services'=>$this->dependents(),'auth'=>$auth,'created_at'=>time(),'expires_at'=>time()+900];
            $this->store->write('control-update-review-'.$id,$j);return $this->publicReview($j,$s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function publicReview(array $j,array $s): array
    {
        return array_intersect_key($j,array_flip(['id','base','target','changes','blockers','dependent_services','auth','expires_at']))+['repository'=>$s['repository'],'branch'=>$s['branch'],'up_to_date'=>$j['base']===$j['target']];
    }
    public function start(array $p): array
    {
        $lock=$this->lock();
        try{
            $this->busy();$s=$this->settings();$id=$this->id($p['review']??'');$j=$this->store->read('control-update-review-'.$id);
            if(!$j||($j['expires_at']??0)<time()||($p['confirm']??false)!==true)throw new OpException('確認の期限と更新の影響を確認してください');
            if($j['blockers']||$j['base']===$j['target'])throw new OpException('適用可能な更新ではありません');
            if($j['configuration']!==hash('sha256',json_encode($s))||$j['local']!==$this->local($s))throw new OpException('確認後にコードまたは設定が変わりました。更新内容を確認し直してください');
            if(!is_file($this->store->root().'/updater/bin/pjas-control-update'))throw new OpException('rootの更新サービスを初回登録してください');
            $job=bin2hex(random_bytes(16));$this->store->write('control-update-job-'.$job,$j+['job'=>$job]);
            $this->state($job,'queued','更新サービスの起動待ち');$this->marker(bin2hex(random_bytes(32)));
            try{$this->must([$this->cfg['systemctl'],'start','--no-block','pjas-control-update@'.$job.'.service']);}catch(\Throwable $e){$this->unmark();$this->state($job,'failed','更新サービスを起動できませんでした',['rollback_complete'=>true]);throw $e;}
            return ['id'=>$job,'phase'=>'queued'];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function id(mixed $id): string{if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new OpException('更新IDが不正です');return $id;}
    private function state(string $id,string $phase,string $detail,array $extra=[]): void{$this->store->write('control-update',['id'=>$id,'phase'=>$phase,'detail'=>$detail,'updated_at'=>gmdate('c')]+$extra);}
    public function status(array $p=[]): array
    {
        $state=$this->store->read('control-update');$s=null;$error='';
        try{$s=$this->settings();}catch(\Throwable $e){$error='サーバーで更新機能の初回登録が必要です';}
        if(in_array($state['phase']??'', ['queued','running','recovering'],true)){
            $unit=$this->run([$this->cfg['systemctl'],'is-active','pjas-control-update@'.($state['id']??'').'.service']);
            $state['interrupted']=!in_array(trim($unit['out']),['active','activating','reloading'],true);
        }
        return ['configured'=>$s!==null,'repository'=>$s['repository']??'','branch'=>$s['branch']??'','state'=>$state,'setup_message'=>$error];
    }
    private function dependents(): array
    {
        $queue=$this->units();$seen=array_fill_keys($queue,true);$extra=[];
        while($queue){
            $unit=array_shift($queue);$out=$this->must([$this->cfg['systemctl'],'show','--no-pager','--property=RequiredBy,RequisiteOf,BoundBy,ConsistsOf,PropagatesStopTo','--',$unit]);
            foreach(explode("\n",$out) as $line){
                if(!str_contains($line,'='))continue;[, $values]=explode('=',$line,2);
                foreach(preg_split('/\s+/',trim($values))?:[] as $dependent){
                    if($dependent===''||isset($seen[$dependent]))continue;
                    if(count($seen)>=50||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.@-]*\.service\z/D',$dependent)||str_starts_with($dependent,'pjas-control-update@'))throw new OpException('停止に連動するサービスの範囲をサーバーで確認してください');
                    $seen[$dependent]=true;$queue[]=$dependent;
                    $state=trim($this->run([$this->cfg['systemctl'],'is-active',$dependent])['out']);
                    if(in_array($state,['activating','deactivating'],true))throw new OpException('連動するサービスの起動・停止が終わってから更新してください');
                    if($state==='active'){
                        $type=$this->must([$this->cfg['systemctl'],'show','--value','--property=Type','--',$dependent]);
                        if(!in_array($type,['simple','exec','forking','notify','notify-reload','dbus'],true))throw new OpException('一度だけ実行する連動サービスはサーバーで保守してください');
                        $extra[]=$dependent;
                    }
                }
            }
        }
        sort($extra);return $extra;
    }
    private function units(): array{return ['pjas-monitor.timer','pjas-monitor.service','pjas-control-web.service','pjas-agentd.service','php'.PhpRuntime::controlVersion().'-fpm.service'];}
    private function marker(string $probe): void
    {
        $dir=$this->cfg['control_update_runtime']??'/run/pjas-control-update';if(is_link($dir)||is_link($dir.'/maintenance'))throw new OpException('更新時の停止情報が不正です');if(!is_dir($dir))mkdir($dir,0755,true);
        file_put_contents($dir.'/maintenance',hash('sha256',$probe));chmod($dir.'/maintenance',0644);
    }
    private function unmark(): void{$file=($this->cfg['control_update_runtime']??'/run/pjas-control-update').'/maintenance';if(is_file($file)&&!unlink($file))throw new OpException('更新中の停止を解除できません');}
    /** Grant the control user's group access only to reviewed Git code; secrets keep their saved modes. */
    private function readableCode(array $settings,array $job,int $gid): void
    {
        foreach($job['changes'] as $relative){
            if(in_array($relative,['config.php','agent/config.php'],true))continue;
            if($relative===''||str_starts_with($relative,'/')||preg_match('~(?:^|/)\.\.?(?:/|$)~',$relative)||str_contains($relative,"\0"))throw new OpException('更新コードの相対パスが不正です');
            $path=$settings['root'].'/'.$relative;if(!file_exists($path))continue;
            $stat=lstat($path);if(is_link($path)||!is_file($path)||$stat['uid']!==$this->owner||$stat['nlink']!==1)throw new OpException('更新コードの所有者・種類を確認してください');
            if(!chgrp($path,$gid)||!chmod($path,($stat['mode']&0111)?0750:0640))throw new OpException('更新コードの専用読み取り権限を設定できません');
            for($parent=dirname($path);$parent!==$settings['root'];$parent=dirname($parent)){
                $st=lstat($parent);if(!$st||is_link($parent)||$st['uid']!==$this->owner||!chgrp($parent,$gid)||!chmod($parent,0750))throw new OpException('更新コードのフォルダ権限を確認してください');
            }
        }
    }
    private function restoreConfigs(array $settings,array $backup,string $directory): void
    {
        foreach($backup['configs'] as $relative=>$stat){
            $dest=$settings['root'].'/'.$relative;$temp=$dest.'.restore-'.bin2hex(random_bytes(8));
            try{
                $out=fopen($temp,'xb');if(!$out||!chmod($temp,0600))throw new OpException('設定の退避先を作成できません');
                $in=fopen($directory.'/'.str_replace('/','-',$relative),'rb');
                try{if(!$in||stream_copy_to_stream($in,$out)===false)throw new OpException('設定を戻せません');}finally{if($in)fclose($in);fclose($out);}
                $this->must(['/usr/bin/cp','--attributes-only','--preserve=mode,ownership,timestamps,xattr','--',$directory.'/'.str_replace('/','-',$relative),$temp]);
                if(!chown($temp,$stat['uid'])||!chgrp($temp,$stat['gid'])||!chmod($temp,$stat['mode'])||!rename($temp,$dest))throw new OpException('設定と保存済みの権限を戻せません');
            }finally{if(is_file($temp))unlink($temp);}
        }
    }
    private function sqlite(string $source,string $dest): void
    {
        $src=new \SQLite3($source,SQLITE3_OPEN_READONLY);$dst=new \SQLite3($dest,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);$src->busyTimeout(10000);$dst->busyTimeout(10000);
        try{if(!$src->backup($dst)||$dst->querySingle('PRAGMA quick_check')!=='ok')throw new OpException('管理DBの整合性保存・復元に失敗しました');}finally{$src->close();$dst->close();}
    }
    private function php(): string{return PhpRuntime::cli('php'.PhpRuntime::controlVersion().'-fpm');}
    private function stop(): void{$this->must(array_merge([$this->cfg['systemctl'],'stop'],$this->units()),null,[],90);}
    private function restoreUnits(array $units): void { $primary=array_reverse($this->units());$order=array_values(array_unique(array_merge($primary,array_keys($units))));foreach($order as $unit)if(!empty($units[$unit])&&!str_starts_with($unit,'pjas-monitor.'))$this->must([$this->cfg['systemctl'],'start',$unit],null,[],60); }
    private function restoreTimer(array $units): void {if(!empty($units['pjas-monitor.timer']))$this->must([$this->cfg['systemctl'],'start','pjas-monitor.timer'],null,[],30);}
    private function probe(string $token,string $dir): void
    {
        $file=$dir.'/probe-header';file_put_contents($file,'X-Pjas-Update-Probe: '.$token."\n");chmod($file,0600);
        try{$code=$this->must(['/usr/bin/curl','--silent','--show-error','--max-time','20','--noproxy','*','--header','@'.$file,'--output','/dev/null','--write-out','%{http_code}','http://127.0.0.1:8184/login'],null,[],25);if($code!=='200')throw new OpException('更新後の管理画面を確認できません');}finally{unlink($file);}
    }
    public function execute(string $id,bool $recover=false): array
    {
        $id=$this->id($id);$lock=$this->lock();$s=$this->settings();$job=$this->store->read('control-update-job-'.$id);$dir=$this->store->directory('control-updates/'.$id);$backup=$this->store->read('control-update-backup-'.$id);$changed=false;$stopped=false;$owned=false;$timerFailed=false;$token=bin2hex(random_bytes(32));
        try{
            if(!$job||($this->store->read('control-update')['id']??'')!==$id)throw new OpException('実行対象の更新を確認できません');
            $owned=true;
            if($recover){
                $state=$this->store->read('control-update');
                if(($state['phase']??'')==='succeeded'||!empty($state['rollback_complete']))return $this->status();
                if($job['configuration']!==hash('sha256',json_encode($s))){$owned=false;throw new OpException('更新設定が変更されています。停止情報を維持します');}
                if(!$backup){
                    $owned=false;
                    if(!in_array($state['detail']??'', ['更新サービスの起動待ち','更新前の検査'],true)||$this->local($s)!==$job['local'])throw new OpException('退避記録がありません。コード・DB・サービスを手動で確認してください');
                    $this->unmark();$this->state($id,'failed','コード・DB変更前に中断しました',['rollback_complete'=>true]);return $this->status();
                }
                $this->state($id,'recovering','退避したコード・管理DBを復旧');$stopped=true;$changed=!empty($backup['complete']);throw new OpException('管理者による復旧');
            }
            if($job['configuration']!==hash('sha256',json_encode($s))||$job['local']!==$this->local($s))throw new OpException('起動前にコード・設定が変わりました');
            $this->state($id,'running','更新前の検査');
            $web=require $s['root'].'/config.php';$db=$web['db'];if(is_link($db)||!is_file($db))throw new OpException('管理DBの保存先を確認してください');
            $read=new \SQLite3($db,SQLITE3_OPEN_READONLY);$busy=(int)$read->querySingle("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','running')");$read->close();if($busy)throw new OpException('実行待ち・実行中の作業があります');
            foreach(glob($this->store->root().'/upgrade-active-*.json')?:[] as $file)if(json_decode(file_get_contents($file),true))throw new OpException('サイトの移行中です');
            $repo=$this->repository();$stage=$dir.'/candidate';if(!mkdir($stage,0700))throw new OpException('確認用フォルダを作成できません');
            $this->must(['/usr/bin/git','--git-dir='.$repo,'archive','--format=tar',$job['target']],null,GitRepositories::publicEnv(),120,null,$dir.'/candidate.tar');
            $this->must(['/usr/bin/tar','-xf',$dir.'/candidate.tar','-C',$stage,'--no-same-owner'],null,[],120);
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage,\FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&(str_ends_with($file->getFilename(),'.php')||str_starts_with($file->getFilename(),'pjas-')))$this->must([$this->php(),'-l',$file->getPathname()],null,[],30);
            if(!str_contains((string)file_get_contents($stage.'/app/bootstrap.php'),'PJAS_CONTROL_UPDATE'))throw new OpException('更新中の排他機構がないバージョンには更新できません');
            $dependents=$this->dependents();if($dependents!==($job['dependent_services']??[]))throw new OpException('停止に連動するサービスが確認後に変わりました');
            $active=[];foreach(array_merge($this->units(),$dependents) as $unit)$active[$unit]=$this->run([$this->cfg['systemctl'],'is-active',$unit])['code']===0;
            foreach(['pjas-control-web.service','pjas-agentd.service','php'.PhpRuntime::controlVersion().'-fpm.service'] as $unit)if(!$active[$unit])throw new OpException('管理サービスを正常に起動してから更新してください');
            $configStats=[];foreach(['config.php','agent/config.php'] as $file){$st=stat($s['root'].'/'.$file);$configStats[$file]=['mode'=>$st['mode']&0777,'uid'=>$st['uid'],'gid'=>$st['gid']];}
            $backup=['complete'=>false,'configs'=>$configStats,'base'=>$job['base'],'target'=>$job['target'],'units'=>$active,'db'=>$db,'db_uid'=>fileowner($db),'db_gid'=>filegroup($db),'db_mode'=>fileperms($db)&0777];
            $this->store->write('control-update-backup-'.$id,$backup);
            $this->marker($token);$stopped=true;$this->state($id,'running','管理処理を停止して退避');$this->stop();
            $read=new \SQLite3($db,SQLITE3_OPEN_READONLY);$pending=(int)$read->querySingle("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','running')");$read->close();if($pending)throw new OpException('停止前に開始された作業があります');
            $this->sqlite($db,$dir.'/database.sqlite');chmod($dir.'/database.sqlite',0600);
            foreach(['config.php','agent/config.php'] as $file){$saved=$dir.'/'.str_replace('/','-',$file);$this->must(['/usr/bin/cp','--preserve=mode,ownership,timestamps,xattr','--',$s['root'].'/'.$file,$saved]);chmod($saved,0600);}
            $this->must(['/usr/bin/git','-C',$s['root'],'archive','--format=tar',$job['base']],null,GitRepositories::publicEnv(),120,null,$dir.'/previous-code.tar');
            $backup['complete']=true;$this->store->write('control-update-backup-'.$id,$backup);
            $this->git(['-c','protocol.file.allow=always','fetch','--no-tags','--no-recurse-submodules',$repo,$job['target']],$s['root']);
            $changed=true;$this->state($id,'running','固定したコミットと管理DBを更新');$this->git(['merge','--ff-only','--no-edit',$job['target']],$s['root']);
            $user=posix_getpwuid($backup['db_uid']);if(!$user||$user['uid']===0)throw new OpException('管理DBの所有者を専用ユーザーにしてください');
            $this->readableCode($s,$job,$user['gid']);
            $this->must([$this->php(),$s['root'].'/bin/pjas-migrate'],$s['root'],['PATH'=>'/usr/bin:/bin','PJAS_CONTROL_UPDATE'=>'1'],120,$user['name']);
            $this->state($id,'running','サービスを起動して管理画面を検査');$this->restoreUnits($active);$this->probe($token,$dir);
            $this->state($id,'succeeded','更新と管理画面の検査が完了しました',['commit'=>$job['target'],'backup'=>$dir]);$this->unmark();
            try{$this->restoreTimer($active);}catch(\Throwable){$this->state($id,'succeeded','コード更新は完了しました。監視タイマーの起動を確認してください',['commit'=>$job['target'],'backup'=>$dir,'monitor_restart_failed'=>true]);}
            return $this->status();
        }catch(\Throwable $e){
            if(!$owned)throw $e;
            $this->store->write('control-update-error-'.$id,['reason'=>$e->getMessage(),'time'=>gmdate('c')]);
            $restored=false;
            try{
                if($changed){
                    if(!$backup)throw new OpException('退避記録を確認できません');$this->marker($token);$this->stop();
                    $head=$this->git(['rev-parse','HEAD'],$s['root']);if(!in_array($head,[$backup['base'],$backup['target']],true))throw new OpException('別のコード変更があります');
                    // Only this transaction's fast-forward is undone; arbitrary local edits are never discarded.
                    $this->local($s);$this->git(['reset','--hard',$backup['base']],$s['root']);
                    $user=posix_getpwuid($backup['db_uid']);if(!$user)throw new OpException('管理DBの実行ユーザーを確認できません');$this->readableCode($s,$job,$user['gid']);$this->restoreConfigs($s,$backup,$dir);
                    $this->sqlite($dir.'/database.sqlite',$backup['db']);chown($backup['db'],$backup['db_uid']);chgrp($backup['db'],$backup['db_gid']);chmod($backup['db'],$backup['db_mode']);
                    $this->restoreUnits($backup['units']);$this->probe($token,$dir);$this->unmark();$restored=true;try{$this->restoreTimer($backup['units']);}catch(\Throwable){$timerFailed=true;}
                }elseif($stopped){$this->restoreUnits($backup['units']);$this->unmark();$restored=true;try{$this->restoreTimer($backup['units']);}catch(\Throwable){$timerFailed=true;}}
                else {$this->unmark();$restored=true;}
            }catch(\Throwable $recovery){$this->store->write('control-update-recovery-error-'.$id,['reason'=>$recovery->getMessage(),'time'=>gmdate('c')]);$restored=false;}
            $detail=$restored?'更新できませんでした。変更前の状態を維持・復旧しました':'更新・自動復旧が完了していません。サーバーで復旧が必要です';
            if($timerFailed)$detail.='。監視タイマーの起動を確認してください';
            $this->state($id,'failed',$detail,['rollback_complete'=>$restored,'backup'=>$dir,'monitor_restart_failed'=>$timerFailed]);
            return $this->status();
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
