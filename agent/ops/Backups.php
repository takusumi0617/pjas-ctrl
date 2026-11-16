<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
require_once dirname(__DIR__).'/lib/SiteIsolation.php';
use Pjas\Agentd\{ControlStore,EncryptedFile,Paths,Spec,Proc,OpException,SharedDatabases,JobLog,SitePause};
require_once dirname(__DIR__).'/lib/ControlStore.php';
require_once dirname(__DIR__).'/lib/SiteLifecycleGuard.php';
require_once dirname(__DIR__).'/lib/EncryptedFile.php';
require_once dirname(__DIR__).'/lib/SharedDatabases.php';

final class Backups
{
    private ControlStore $store;
    public function __construct(private array $cfg,private Paths $paths){$this->store=new ControlStore($cfg);}
    private function directory(string $name): string {return $this->store->directory('backups/'.Spec::name($name));}
    private function id(mixed $id): string {if(!is_string($id)||!preg_match('/\A[0-9]{8}T[0-9]{6}-[a-f0-9]{16}\z/D',$id))throw new OpException('バックアップの番号が不正です');return $id;}
    private function meta(string $name,string $id): array
    {
        $file=$this->directory($name).'/'.$this->id($id).'.json';if(!is_file($file)||is_link($file))throw new OpException('バックアップが見つかりません');
        $m=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if(($m['service']??'')!==$name)throw new OpException('別サイトのバックアップです');return $m;
    }
    private function lock(string $name,bool $wait=true)
    {
        $path=rtrim($this->cfg['apache_sites'],'/').'/.pjas-deploy-'.Spec::name($name).'.lock';if(is_link($path))throw new OpException('ロックが不正です');$fp=fopen($path,'c');if(!$fp)throw new OpException('公開作業をロックできません');if(!flock($fp,$wait?LOCK_EX:(LOCK_EX|LOCK_NB))){fclose($fp);if(!$wait)return null;throw new OpException('公開作業をロックできません');}try{\Pjas\Agentd\SiteLifecycleGuard::active($this->cfg,$name);}catch(\Throwable $e){flock($fp,LOCK_UN);fclose($fp);throw $e;}return $fp;
    }
    private function records(string $name): array
    {
        $rows=[];
        foreach(glob($this->directory($name).'/*.json')?:[] as $file){if(is_link($file))continue;$m=json_decode(file_get_contents($file),true);if(is_array($m)&&($m['service']??'')===$name)$rows[]=$m;}
        usort($rows,static fn($a,$b)=>strcmp($b['id'],$a['id']));return $rows;
    }
    public function listing(array $params): array {return ['backups'=>array_slice($this->records(Spec::name($params['service']['name']??'')),0,100)];}
    private static function sqlite(string $file): bool { $fp=@fopen($file,'rb');if(!$fp)return false;$h=fread($fp,16);fclose($fp);return $h==="SQLite format 3\0"; }
    public function copyDataFile(string $source,string $dest): void
    {
        if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);
        if(file_exists($dest)||is_link($dest))throw new OpException('バックアップ先が既に存在します');
        $fp=fopen($dest,'x');if(!$fp)throw new OpException('バックアップ先を作成できません');chmod($dest,0600);fclose($fp);
        try{
            $r=Proc::run(['/usr/bin/python3',dirname(__DIR__).'/lib/copy_data.py',$source],null,600,$this->cfg['web_user'],['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8'],null,$dest);
            if($r['code']!==0){$kind=preg_match('/PJAS_DATA_ERROR:(permission|sqlite|io)/',$r['err'],$m)?$m[1]:'unknown';throw new OpException('共有データを安全に読み取れません。対象: '.json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).' / 実行ユーザー: '.$this->cfg['web_user'].' / 種別: '.$kind);}
        }catch(\Throwable $e){@unlink($dest);throw $e;}
    }

    public function snapshotData(string $name,string $dest,bool $environment=true,array $spec=[]): array
    {
        $shared=$this->paths->shared($name);if(is_link($shared))throw new OpException('共有フォルダが不正です');$count=0;$databases=[];
        if(!is_dir($dest))mkdir($dest,0700,true);
        if(is_dir($shared)){
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($shared,\FilesystemIterator::SKIP_DOTS));
            foreach($it as $f){if($f->isLink()||!$f->isFile())continue;$rel=substr($f->getPathname(),strlen($shared)+1);
                if(!$environment&&preg_match('~(^|/)\.env(?:\..*)?$~',$rel))continue;
                if(preg_match('/-(wal|shm|journal)$/',$rel)&&self::sqlite(preg_replace('/-(wal|shm|journal)$/','',$f->getPathname())))continue;
                $this->copyDataFile($f->getPathname(),$dest.'/'.$rel);$count++;
            }
        }
        $current=$this->paths->currentTag($name)===null?$this->paths->base($name):$this->paths->assertInside($name,$this->paths->current($name));
        if($this->paths->currentTag($name)===null&&class_exists(Deploy::class)){ $flat=Deploy::adoptPlan($current)['flatten'];if($flat!==null)$current.='/'.$flat; }
        foreach(($spec['shared']??[]) as $relative){
            if(!$environment&&preg_match('~(^|/)\.env(?:\..*)?$~',$relative))continue;$source=$current.'/'.$relative;
            if(is_link($source)||!file_exists($source))continue;
            $items=is_dir($source)?new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS)):[new \SplFileInfo($source)];
            foreach($items as $f){if($f->isLink()||!$f->isFile())continue;$rel=is_dir($source)?$relative.'/'.substr($f->getPathname(),strlen($source)+1):$relative;
                if(preg_match('/-(wal|shm|journal)$/',$rel)&&self::sqlite(preg_replace('/-(wal|shm|journal)$/','',$f->getPathname())))continue;
                if(!file_exists($dest.'/'.$rel)){$this->copyDataFile($f->getPathname(),$dest.'/'.$rel);$count++;}}
        }
        foreach(SharedDatabases::discover($current) as $rel){if(preg_match('~^(?:releases|shared|current)/~',$rel))continue;$databases[]=$rel;
            if(!file_exists($dest.'/'.$rel)){$this->copyDataFile($current.'/'.$rel,$dest.'/'.$rel);$count++;}}
        return ['files'=>$count,'databases'=>$databases];
    }
    public function create(array $params): array
    {
        $spec=Spec::service($params['service']??null);$lock=$this->lock($spec['name']);
        try{return $this->createUnlocked($spec,(string)($params['reason']??'manual'));}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function createUnlocked(array $spec,string $reason='before-deploy'): array
    {
        $name=$spec['name'];$id=gmdate('Ymd\THis').'-'.bin2hex(random_bytes(8));$dir=$this->directory($name);$tmp=$this->store->directory('work').'/backup-'.bin2hex(random_bytes(12));mkdir($tmp,0700);
        $file=$dir.'/'.$id.'.pjas';
        try{
            $roots=[$this->paths->shared($name)];$current=$this->paths->currentTag($name)===null?$this->paths->base($name):$this->paths->assertInside($name,$this->paths->current($name));
            foreach($spec['shared'] as $relative)$roots[]=$current.'/'.$relative;
            foreach(SharedDatabases::discover($current) as $relative)if(!preg_match('~^(?:releases|shared|current)/~',$relative))$roots[]=$current.'/'.$relative;
            $estimate=0;$seen=[];
            foreach($roots as $source){if(!file_exists($source)||is_link($source))continue;
                $entries=is_dir($source)?new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS)):[new \SplFileInfo($source)];
                foreach($entries as $entry){$real=$entry->getRealPath();if($entry->isLink()||!$entry->isFile()||isset($seen[$real]))continue;$seen[$real]=true;$estimate+=$entry->getSize();}}
            if(disk_free_space($dir)<128*1024*1024+3*$estimate)throw new OpException('バックアップに必要な空き容量がありません');
            mkdir($tmp.'/shared',0700);$data=$this->snapshotData($name,$tmp.'/shared',true,$spec);
            Proc::must(['/bin/tar','-czf',$tmp.'/data.tar.gz','-C',$tmp,'--','shared'],null,600);
            EncryptedFile::encrypt($tmp.'/data.tar.gz',$file,$this->store->key());
            $meta=['id'=>$id,'service'=>$name,'created_at'=>gmdate('c'),'reason'=>substr($reason,0,100),'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),
                'release'=>$this->paths->currentTag($name),'files'=>$data['files'],'databases'=>$data['databases']];
            file_put_contents($dir.'/'.$id.'.json',json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));chmod($dir.'/'.$id.'.json',0600);return $meta;
        }catch(\Throwable $e){@unlink($file);throw $e;}finally{Proc::run(['/bin/rm','-rf','--',$tmp],null,120);}
    }
    public function download(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$id=$this->id($params['id']??'');$meta=$this->meta($name,$id);$file=$this->directory($name).'/'.$id.'.pjas';
        $offset=filter_var($params['offset']??0,FILTER_VALIDATE_INT);if($offset===false||$offset<0||$offset>$meta['bytes']||is_link($file))throw new OpException('読み取り位置が不正です');
        $fp=fopen($file,'rb');touch($file);fseek($fp,$offset);$chunk=fread($fp,1024*1024);fclose($fp);return ['data'=>base64_encode($chunk),'size'=>$meta['bytes'],'eof'=>$offset+strlen($chunk)>=$meta['bytes']];
    }
    public function keyInfo(array $params): array {return ['key'=>base64_encode($this->store->key())];}
    public function restore(array $params): array
    {
        $spec=Spec::service($params['service']??null);$name=$spec['name'];$id=$this->id($params['id']??'');
        if(empty($params['writers_stopped'])||empty((new SitePause($this->cfg))->read($name)['enabled']))throw new OpException('先にサイトを一時停止し、常駐プログラム・定期処理などDBへの書き込みを止めてください');
        $lock=$this->lock($name);$apache=fopen($this->cfg['apache_sites'].'/.pjas-vhost.lock','c');if(!$apache||!flock($apache,LOCK_EX)){flock($lock,LOCK_UN);fclose($lock);throw new OpException('公開設定をロックできません');}$tmp=$this->store->directory('work').'/restore-'.bin2hex(random_bytes(12));mkdir($tmp,0700);
        $shared=$this->paths->shared($name);$old=$this->paths->base($name).'/.shared-before-'.bin2hex(random_bytes(8));$swapped=false;
        try{
            if(empty((new SitePause($this->cfg))->read($name)['enabled']))throw new OpException('サイトの一時停止状態を確認してください');
            $meta=$this->meta($name,$id);$file=$this->directory($name).'/'.$id.'.pjas';
            if(is_link($file)||!hash_equals($meta['sha256'],hash_file('sha256',$file)))throw new OpException('バックアップが破損しています');
            EncryptedFile::decrypt($file,$tmp.'/data.tar.gz',$this->store->key());
            $entries=Proc::must(['/bin/tar','-tzf',$tmp.'/data.tar.gz'],null,120);
            foreach(explode("\n",trim($entries)) as $entry)if(!preg_match('~\Ashared(?:/[^\r\n]*)?\z~D',$entry)||in_array('..',explode('/',$entry),true))throw new OpException('バックアップ内のパスが不正です');
            $types=Proc::must(['/bin/tar','-tvzf',$tmp.'/data.tar.gz'],null,120);
            foreach(explode("\n",trim($types)) as $entry)if($entry!==''&&!in_array($entry[0],['-','d'],true))throw new OpException('バックアップ内に復元できない種類のファイルがあります');
            Proc::must(['/bin/tar','-xzf',$tmp.'/data.tar.gz','-C',$tmp,'--no-same-owner','--no-same-permissions'],null,600);
            $tree=$tmp.'/shared';if(!is_dir($tree)||is_link($tree))throw new OpException('復元内容が不正です');
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isLink()||!$f->isFile())throw new OpException('復元内容に不正なファイルがあります');
            $safety=$this->createUnlocked($spec,'before-restore');
            $incoming=$this->paths->base($name).'/.shared-incoming-'.bin2hex(random_bytes(8));mkdir($incoming,0700);
            Proc::must(['/bin/cp','-a','--',$tree.'/.',$incoming],null,600);
            Proc::must(['/bin/chown','-Rh',$this->cfg['web_user'].':'.$this->cfg['web_group'],$incoming],null,120);chmod($incoming,0770);
            if(is_link($shared)||!rename($shared,$old))throw new OpException('共有データを退避できません');
            if(!rename($incoming,$shared)){rename($old,$shared);throw new OpException('共有データを復元できません');}$swapped=true;
            $current=$this->paths->currentTag($name)===null?null:$this->paths->assertInside($name,$this->paths->current($name));
            if($current!==null)(new SharedDatabases($this->cfg,$this->paths))->link($name,$current,$meta['databases']??[],null,new JobLog((int)($params['job_id']??0),$this->cfg['job_dir']));
            \Pjas\Agentd\SiteIsolation::protect($this->cfg,$name,$spec['docroot']);
            if($spec['runtime']!=='static')Proc::must([$this->cfg['systemctl'],'reload',$spec['runtime']],null,60);
            Proc::must([$this->cfg['apachectl'],'graceful'],null,60);
            Proc::run(['/bin/rm','-rf','--',$old],null,300);$swapped=false;
            return ['restored'=>$id,'safety_backup'=>$safety['id'],'databases'=>$meta['databases']??[]];
        }catch(\Throwable $e){if($swapped){$failed=$shared.'.failed-'.bin2hex(random_bytes(5));@rename($shared,$failed);@rename($old,$shared);Proc::run(['/bin/rm','-rf','--',$failed],null,120);}throw $e;
        }finally{if(isset($incoming)&&is_dir($incoming)&&!is_link($incoming))Proc::run(['/bin/rm','-rf','--',$incoming],null,120);Proc::run(['/bin/rm','-rf','--',$tmp],null,120);flock($apache,LOCK_UN);fclose($apache);flock($lock,LOCK_UN);fclose($lock);}
    }
    public function prune(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$keep=max(2,min(100,(int)($params['keep']??14)));$days=max(1,min(365,(int)($params['days']??30)));
        $lock=$this->lock($name,false);if(!$lock)return ['deleted'=>[],'busy'=>true];
        try{$rows=$this->records($name);$deleted=[];
        foreach($rows as $i=>$m){if($i<$keep||strtotime($m['created_at'])>time()-$days*86400)continue;$id=$this->id($m['id']);$file=$this->directory($name).'/'.$id.'.pjas';if(is_file($file)&&filemtime($file)>time()-600)continue;@unlink($this->directory($name).'/'.$id.'.pjas');@unlink($this->directory($name).'/'.$id.'.json');$deleted[]=$id;}return ['deleted'=>$deleted];}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
