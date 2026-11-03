<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{ControlStore,Paths,Spec,Proc,OpException,JobLog,SiteLifecycleGuard};

/** Tombstones and recovery material are root-only; the site ID and its audit history stay in the web DB. */
final class SiteLifecycle
{
    private ControlStore $store;
    public function __construct(private array $cfg,private Paths $paths){$this->store=new ControlStore($cfg);}
    private function name(array $p):string{
        $name=Spec::name($p['service']['name']??'');
        if(in_array($name,['pjas-ctrl','pjas-control'],true)||realpath($this->paths->base($name))===realpath(dirname(__DIR__,2)))throw new OpException('管理画面自身は削除できません');
        $conf=$this->cfg['apache_sites'].'/pjas-'.$name.'.conf';$body=is_file($conf)?file_get_contents($conf):'';
        if(str_contains($body,'http://127.0.0.1:8184')||str_contains($body,dirname(__DIR__,2).'/public'))throw new OpException('管理画面自身の公開設定は削除できません');
        return $name;
    }
    private function record(string $n):array{return $this->store->read('retired-'.$n);}
    private function directory(string $n):string{return $this->store->directory('retired/'.$n);}
    private function payload(string $n):string{return (new ControlStore(['control_dir'=>$this->cfg['www_root'].'/.pjas-retired']))->directory($n).'/site';}
    private function registry():array{$f=$this->cfg['daemon_dir'].'/registry.json';return is_file($f)?json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR):[];}
    private function writeRegistry(array $rows):void{
        $file=$this->cfg['daemon_dir'].'/registry.json';$tmp=$file.'.tmp-'.bin2hex(random_bytes(6));
        if(file_put_contents($tmp,json_encode($rows,JSON_THROW_ON_ERROR))===false)throw new OpException('常駐プログラムの関連付けを保存できません');chmod($tmp,0644);
        if(!rename($tmp,$file))throw new OpException('関連付けを保存できません');
    }
    public function status(array $p):array{
        $r=$this->record(Spec::name($p['service']['name']??''));
        return array_intersect_key($r,array_flip(['state','mode','expires','deleted_at','backup','error']));
    }
    private function references(string $text,string $base):bool{
        return preg_match('~'.preg_quote($base,'~').'(?=/|[\\s"\x27]|$)~',$text)===1;
    }
    public function plan(array $p):array{
        $n=$this->name($p);$base=$this->paths->base($n);$conf=$this->cfg['apache_sites'].'/pjas-'.$n.'.conf';
        if(is_link($base)||!is_dir($base))throw new OpException('サイトの保存先が見つからないか、通常のフォルダではありません');
        $daemons=array_values(array_filter($this->registry(),static fn($r)=>$r['service']===$n));$tasks=[];
        foreach(glob($this->cfg['cron_script_dir'].'/task-*.sh')?:[] as $f){if(!is_link($f)&&str_contains(file_get_contents($f),'cd -- '.escapeshellarg($base.'/current')."\n"))$tasks[]=(int)substr(basename($f),5,-3);}
        $blockers=[];if(!empty($this->store->read('preview-state-'.$n)))$blockers[]='先に試験公開の画面から試験URLの公開を終了してください';
        foreach(glob(($this->cfg['apache_sites_enabled']??dirname($conf).'/../sites-enabled').'/*.conf')?:[] as $f){
            if(basename($f)===basename($conf))continue;
            if($this->references((string)file_get_contents($f),$base))$blockers[]='別の公開設定がこのサイトのファイルを使っています: '.basename($f);
        }
        $known=array_column($daemons,'unit');
        foreach(glob($this->cfg['daemon_unit_dir'].'/*.service')?:[] as $f){if(!in_array(basename($f),$known,true)&&$this->references((string)file_get_contents($f),$base))$blockers[]='関連付け外の常駐プログラムが使っています: '.basename($f);}
        foreach(glob($this->cfg['cron_dir'].'/*')?:[] as $f){if(is_file($f)&&$this->references((string)file_get_contents($f),$base))$blockers[]='管理対象外の定期処理が使っています: '.basename($f);}
        return ['service'=>$n,'paused'=>!empty((new \Pjas\Agentd\SitePause($this->cfg))->read($n)['enabled']),'daemons'=>array_map(static fn($r)=>['unit'=>$r['unit'],'label'=>$r['label'],'managed'=>(bool)$r['managed']],$daemons),'tasks'=>$tasks,'blockers'=>$blockers,'fingerprint'=>hash('sha256',json_encode([$daemons,$tasks,$blockers,is_file($conf)?hash_file('sha256',$conf):null]))];
    }
    public function start(array $p):array{
        $this->name($p);$id=(int)($p['job_id']??0);if($id<=0)throw new OpException('作業番号がありません');
        if(!in_array($p['action']??'', ['delete','restore'],true))throw new OpException('操作が不正です');
        $pid=pcntl_fork();if($pid<0)throw new OpException('削除作業を起動できません');
        if($pid===0){posix_setsid();$log=new JobLog($id,$this->cfg['job_dir']);try{$r=$p['action']==='restore'?$this->restore($p):$this->delete($p);$log->set('result',$r);$log->finish('succeeded','完了しました。「削除済みサイト」で結果を確認できます');}catch(\Throwable $e){$log->finish('failed',$e->getMessage());}exit(0);}return ['job_id'=>$id,'pid'=>$pid];
    }
    private function locks(string $n):array{
        $out=[];
        try{foreach([$this->store->directory('locks').'/preview-'.$n,$this->cfg['apache_sites'].'/.pjas-deploy-'.$n.'.lock',$this->cfg['daemon_dir'].'/lock',$this->cfg['apache_sites'].'/.pjas-vhost.lock'] as $path){if(is_link($path))throw new OpException('作業ロックが不正です');if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);$fp=fopen($path,'c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB)){if($fp)fclose($fp);throw new OpException('別の作業が実行中です。終了後にやり直してください');}$out[]=$fp;}return $out;}catch(\Throwable $e){$this->unlock($out);throw $e;}
    }
    private function unlock(array $locks):void{foreach(array_reverse($locks) as $f){flock($f,LOCK_UN);fclose($f);}}
    private function paths(string $n,array $plan,array $rows):array{
        $files=[$this->cfg['apache_sites'].'/pjas-'.$n.'.conf'];
        $preview='preview-'.substr(hash('sha256',$n),0,12);$unit=$this->cfg['daemon_unit_dir'].'/pjas-'.$preview.'.service';
        if(is_file($unit)){if(!str_contains(file_get_contents($unit),'Description=pjas isolated preview '.$preview))throw new OpException('試験環境の定義が他の設定と競合しています');$files[]=$unit;}
        $pool=$this->store->directory('previews').'/'.$preview.'.conf';if(is_file($pool))$files[]=$pool;
        foreach($plan['tasks'] as $id){$files[]=$this->cfg['cron_dir'].'/pjas-task-'.$id;$files[]=$this->cfg['cron_script_dir'].'/task-'.$id.'.sh';}
        foreach($rows as $r)if($r['service']===$n&&!empty($r['managed'])){$files[]=$this->cfg['daemon_unit_dir'].'/'.$r['unit'];$files[]=$this->cfg['daemon_dir'].'/'.$r['unit'].'.sh';}
        return $files;
    }
    private function saveFiles(array $files,string $dir):array{
        $out=[];foreach($files as $i=>$f){if(is_link($f))throw new OpException('退避する設定がリンクになっています: '.basename($f));if(!is_file($f))continue;$st=stat($f);$dest=$dir.'/file-'.$i;if(!copy($f,$dest))throw new OpException('設定を退避できません');chmod($dest,0600);$out[]=['path'=>$f,'copy'=>$dest,'mode'=>$st['mode']&0777,'hash'=>hash_file('sha256',$f)];}return $out;
    }
    private function restoreFiles(array $files,bool $absent=false):void{
        foreach($files as $f){$p=$f['path'];if($absent&&(file_exists($p)||is_link($p)))throw new OpException('同名の設定があるため復元できません: '.basename($p));}
        foreach($files as $f){if(!copy($f['copy'],$f['path']))throw new OpException('設定を復元できません');chmod($f['path'],$f['mode']);}
    }
    public function delete(array $p):array{
        $n=$this->name($p);$mode=$p['mode']??'';if(!in_array($mode,['detach','delete'],true))throw new OpException('削除方法を選んでください');
        $locks=$this->locks($n);$changed=false;$m=[];
        try{
            SiteLifecycleGuard::active($this->cfg,$n);$plan=$this->plan($p);
            if(!hash_equals($plan['fingerprint'],(string)($p['fingerprint']??'')))throw new OpException('確認後に関連設定が変わりました。削除内容を確認し直してください');
            if($mode==='delete'&&($plan['blockers']||empty($p['writers_stopped'])||empty((new \Pjas\Agentd\SitePause($this->cfg))->read($n)['enabled'])))throw new OpException('関連付け外の設定を整理し、DBへの書き込みを停止したことを確認してください');
            $base=$this->paths->base($n);$dir=$this->directory($n);$rows=$this->registry();$linked=array_filter($rows,static fn($r)=>$r['service']===$n);
            $m=['state'=>'deleting','mode'=>$mode,'deleted_at'=>time(),'expires'=>$mode==='delete'?time()+30*86400:0,'files'=>[],'identity'=>[stat($base)['dev'],stat($base)['ino']],'daemons'=>$linked,'enabled'=>is_link(($this->cfg['apache_sites_enabled']??'').'/pjas-'.$n.'.conf')];
            // Durable intent blocks all future RPC mutations, even when the worker is interrupted.
            $this->store->write('retired-'.$n,$m);
            if($mode==='delete'){
                $m['files']=$this->saveFiles($this->paths($n,$plan,$rows),$dir);$this->store->write('retired-'.$n,$m);$changed=true;
                foreach($m['files'] as $f)if(str_starts_with($f['path'],$this->cfg['cron_dir'].'/')&&!unlink($f['path']))throw new OpException('定期処理を停止できません');
                $previewUnit='pjas-preview-'.substr(hash('sha256',$n),0,12).'.service';if(is_file($this->cfg['daemon_unit_dir'].'/'.$previewUnit))Proc::must([$this->cfg['systemctl'],'disable','--now','--',$previewUnit],null,60);
                foreach($linked as $r)Proc::must([$this->cfg['systemctl'],'disable','--now','--',$r['unit']],null,60);
                $m['backup']=(new Backups($this->cfg,$this->paths))->createUnlocked(Spec::service($p['service']),'before-site-delete');
                $this->store->write('retired-'.$n,$m);
                Proc::must([$this->cfg['a2dissite'],'-q','pjas-'.$n],null,30);
                $changed=true;foreach($m['files'] as $f)if(is_file($f['path'])&&!unlink($f['path']))throw new OpException('設定を取り除けません');
                $changed=true;(new Vhost($this->cfg,$this->paths))->reload();
                // Atomic move on the site volume; cross-device moves are rejected without deleting data.
                if(file_exists($dir.'/site'))throw new OpException('前回の退避データが残っています。管理者が状態を確認してください');
                if(file_exists($this->payload($n))||!rename($base,$this->payload($n)))throw new OpException('同じディスク上の保管場所へ移動できません。元のデータは削除していません');
                $previewRoot=rtrim($this->cfg['preview_root']??'/var/www/pjas-previews','/').'/preview-'.substr(hash('sha256',$n),0,12);
                if(is_dir($previewRoot)){if(is_link($previewRoot)||!rename($previewRoot,dirname($this->payload($n)).'/preview'))throw new OpException('終了した試験環境を退避できません');$m['preview']=$previewRoot;$this->store->write('retired-'.$n,$m);}
                Proc::must([$this->cfg['systemctl'],'daemon-reload'],null,30);
            }
            $this->store->write('retired-'.$n,$m);
            $this->writeRegistry(array_filter($rows,static fn($r)=>$r['service']!==$n));
            $m['state']=$mode==='delete'?'deleted':'detached';$this->store->write('retired-'.$n,$m);return $this->status($p);
        }catch(\Throwable $e){
            if($m){$m['state']='failed';$m['error']=$e->getMessage();
                if($changed&&is_dir($this->paths->base($n))){try{$this->restoreFiles($m['files']);if($m['enabled'])Proc::must([$this->cfg['a2ensite'],'-q','pjas-'.$n],null,30);(new Vhost($this->cfg,$this->paths))->reload();}catch(\Throwable $rollback){$m['error'].=' / 設定の復元も失敗: '.$rollback->getMessage();}}
                $this->store->write('retired-'.$n,$m);
            }throw $e;
        }finally{$this->unlock($locks);}
    }
    public function restore(array $p):array{
        $n=$this->name($p);$locks=$this->locks($n);$mutated=false;$m=[];
        try{
            $m=$this->record($n);if(!in_array($m['state']??'', ['deleted','detached','failed','deleting'],true))throw new OpException('完了した削除・管理解除だけを復元できます');
            if($m['state']==='deleted'&&$m['expires']&&$m['expires']<=time())throw new OpException('30日の復元期限が過ぎています');
            $rows=$this->registry();foreach($m['daemons'] as $unit=>$row)if(isset($rows[$unit])&&$rows[$unit]!==$row)throw new OpException('常駐プログラムは別のサイトに関連付けられています');
            $base=$this->paths->base($n);$dir=$this->directory($n);$previewSource=dirname($this->payload($n)).'/preview';$previewTarget=rtrim($this->cfg['preview_root']??'/var/www/pjas-previews','/').'/preview-'.substr(hash('sha256',$n),0,12);
            if(is_dir($previewSource)&&(file_exists($previewTarget)||is_link($previewTarget)))throw new OpException('試験環境の保存先が既にあります。上書きせず中断しました');
            if(in_array($m['state'],['failed','deleting'],true)&&is_dir($base)&&!is_link($base)){
                if(file_exists($this->payload($n))||[stat($base)['dev'],stat($base)['ino']]!==($m['identity']??null))throw new OpException('元の保存先と一致しません。上書きせず中断しました');
                foreach($m['files'] as $f)if(is_link($f['path'])||(is_file($f['path'])&&!hash_equals($f['hash'],hash_file('sha256',$f['path']))))throw new OpException('失敗後に設定が変更されています。上書きせず中断しました');
                $mutated=true;$this->restoreFiles(array_filter($m['files'],fn($f)=>!str_starts_with($f['path'],$this->cfg['cron_dir'].'/')));
                if($m['enabled'])Proc::must([$this->cfg['a2ensite'],'-q','pjas-'.$n],null,30);
                (new Vhost($this->cfg,$this->paths))->reload();
            }elseif($m['mode']==='delete'){
                if(file_exists($base)||is_link($base))throw new OpException('同名のフォルダがあるため上書きせず中断しました');
                foreach($m['files'] as $f)if(file_exists($f['path'])||is_link($f['path']))throw new OpException('同名の設定があるため復元できません');
                $mutated=true;if(!rename($this->payload($n),$base))throw new OpException('保管データを元の場所へ戻せません');
                try{$this->restoreFiles(array_filter($m['files'],fn($f)=>!str_starts_with($f['path'],$this->cfg['cron_dir'].'/')),true);if($m['enabled'])Proc::must([$this->cfg['a2ensite'],'-q','pjas-'.$n],null,30);(new Vhost($this->cfg,$this->paths))->reload();}
                catch(\Throwable $e){Proc::run([$this->cfg['a2dissite'],'-q','pjas-'.$n],null,30);foreach($m['files'] as $f)@unlink($f['path']);Proc::run([$this->cfg['apachectl'],'graceful'],null,60);if(!rename($base,$this->payload($n)))throw new OpException('設定検査が失敗し、データの再退避も失敗しました。管理者が状態を確認してください');throw $e;}
                // Cron must remain stopped until explicitly enabled from its management screen.
                foreach($m['files'] as $f)if(str_starts_with($f['path'],$this->cfg['cron_dir'].'/')&&is_file($f['path']))unlink($f['path']);
                Proc::must([$this->cfg['systemctl'],'daemon-reload'],null,30);
            }
            foreach($m['files'] as $f)if(str_starts_with($f['path'],$this->cfg['cron_dir'].'/')&&is_file($f['path']))unlink($f['path']);
            if(is_dir($previewSource)&&!rename($previewSource,$previewTarget))throw new OpException('試験環境のデータを戻せません');
            $this->writeRegistry($rows+$m['daemons']);$this->store->write('retired-'.$n,[]);
            Proc::run(['/bin/rm','-rf','--',$dir,dirname($this->payload($n))],null,1800);return ['state'=>'active','mode'=>$m['mode']];
        }catch(\Throwable $e){if($mutated&&$m){$m['state']='failed';$m['error']=$e->getMessage();$this->store->write('retired-'.$n,$m);}throw $e;}finally{$this->unlock($locks);}
    }
    public function cleanupStart(array $p=[]):array{
        $pid=pcntl_fork();if($pid<0)throw new OpException('期限の確認作業を開始できません');
        if($pid===0){posix_setsid();try{$this->cleanup();}catch(\Throwable $e){syslog(LOG_ERR,'site cleanup: '.$e->getMessage());}exit(0);}return ['started'=>true];
    }
    public function cleanup(array $p=[]):array{
        $global=fopen($this->store->directory('locks').'/retired-cleanup','c');if(!$global||!flock($global,LOCK_EX|LOCK_NB)){if($global)fclose($global);return ['purged'=>0];}
        try{$count=0;foreach(glob($this->store->directory().'/retired-*.json')?:[] as $f){$n=substr(basename($f),8,-5);$m=$this->record(Spec::name($n));if(($m['state']??'')!=='deleted'||($m['expires']??PHP_INT_MAX)>time())continue;
            try{$locks=$this->locks($n);}catch(OpException){continue;}try{$m=$this->record($n);if(($m['state']??'')!=='deleted'||$m['expires']>time())continue;Proc::must(['/bin/rm','-rf','--',dirname($this->payload($n)),$this->directory($n)],null,1800);$m['state']='expired';$m['files']=[];$m['daemons']=[];$this->store->write('retired-'.$n,$m);$count++;}finally{$this->unlock($locks);}}
        return ['purged'=>$count];}finally{flock($global,LOCK_UN);fclose($global);}
    }
}
