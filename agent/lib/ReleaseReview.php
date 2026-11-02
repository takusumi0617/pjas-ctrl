<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once __DIR__.'/ControlStore.php';
require_once __DIR__.'/ApacheHistory.php';
final class ReleaseReview
{
    private ControlStore $store;
    public function __construct(private array $cfg,private Paths $paths){$this->store=new ControlStore($cfg);}
    public function directory(string $token): string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$token))throw new OpException('公開準備の番号が不正です');return $this->store->directory('preparations').'/'.$token;
    }
    public static function dataPath(string $path,array $spec): bool
    {
        foreach($spec['shared'] as $p)if($path===$p||str_starts_with($path,$p.'/'))return true;
        return (bool)preg_match('~(^|/)(?:\.env(?:\..*)?|[^/]+\.(?:db|sqlite|sqlite3)(?:-(?:wal|shm|journal))?)$~i',$path);
    }
    public function scan(string $root,array $spec,bool $current=false): array
    {
        if(!is_dir($root))return [];$out=[];$total=0;$count=0;$shared=realpath($this->paths->shared($spec['name']));
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $f){if(++$count>100000)throw new OpException('ファイル数が多すぎます（10万件まで）');$rel=substr($f->getPathname(),strlen(rtrim($root,'/'))+1);
            if($f->isLink()){$real=realpath($f->getPathname());if($current&&$shared&&$real&&str_starts_with($real.'/',$shared.'/'))continue;
                if(!$real||!str_starts_with($real.'/',realpath($root).'/'))throw new OpException('外部を指すリンクを含むため準備できません: '.$rel);
                if(!self::dataPath($rel,$spec))$out[$rel]='link:'.readlink($f->getPathname());
            }elseif($f->isFile()){if($current&&self::dataPath($rel,$spec))continue;$total+=$f->getSize();if($total>4*1024*1024*1024)throw new OpException('展開後のファイルが大きすぎます（4GBまで）');
                if(!self::dataPath($rel,$spec))$out[$rel]=hash_file('sha256',$f->getPathname());}
        }
        ksort($out);return $out;
    }
    public static function digest(array $files): string {return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));}
    public function save(string $token,array $spec,array $source,array $meta,array $options=[]): array
    {
        $tree=$this->directory($token).'/tree';$new=$this->scan($tree,$spec);
        // DBなど比較対象外のファイルも、公開までに差し替わらないよう全体を別途固定する。
        $integrity=$this->integrity($tree);
        $tag=$this->paths->currentTag($spec['name']);$current=$tag===null?null:$this->paths->assertInside($spec['name'],$this->paths->current($spec['name']));$old=$current===null?[]:$this->scan($current,$spec,true);
        $changes=[];$counts=['added'=>0,'modified'=>0,'deleted'=>0];$warnings=[];
        foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $path){$status=!isset($old[$path])?'added':(!isset($new[$path])?'deleted':($old[$path]!==$new[$path]?'modified':''));if($status==='')continue;$counts[$status]++;
            if(count($changes)<1000)$changes[]=['path'=>$path,'status'=>$status];
            if(preg_match('~(?:^|/)(?:migrations?|schema)(?:/|\.)|(?:^|/)schema\.sql$~i',$path))$warnings['migration']='DBの構造に関係する変更があります。古いコードとの互換性とバックアップを確認してください';}
        foreach($spec['hooks'] as $hook)if(str_contains(implode(' ',$hook),'migrat'))$warnings['migration']='公開準備でDBの移行処理を実行します。自動復帰してもDBの構造は元に戻りません';
        $result=['token'=>$token,'service'=>$spec['name'],'created'=>time(),'expires'=>time()+32*86400,'baseline_tag'=>$tag,'baseline_hash'=>self::digest($old),'integrity'=>$integrity,
            'options'=>['label'=>substr((string)($options['label']??''),0,60),'auto_rollback'=>!empty($options['auto_rollback']),'maintenance'=>!empty($options['maintenance'])],'source'=>$source,'meta'=>$meta,'counts'=>$counts,'changes'=>$changes,'warnings'=>array_values($warnings),'spec'=>$spec];
        file_put_contents($this->directory($token).'/plan.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));chmod($this->directory($token).'/plan.json',0600);return $result;
    }
    private function integrity(string $root): string
    {
        $out=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $f){$rel=substr($f->getPathname(),strlen($root)+1);if($f->isLink())$out[$rel]='link:'.readlink($f->getPathname());elseif($f->isFile())$out[$rel]=hash_file('sha256',$f->getPathname());}ksort($out);return self::digest($out);
    }
    public function load(string $name,string $token,bool $checkCurrent=false): array
    {
        $dir=$this->directory($token);if(!is_dir($dir)||is_link($dir)||!is_file($dir.'/plan.json')||is_link($dir.'/plan.json'))throw new OpException('公開準備の記録がありません。もう一度準備してください');
        $p=json_decode(file_get_contents($dir.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
        if($p['service']!==Spec::name($name)||$p['expires']<time())throw new OpException('公開準備の期限が切れたか、対象サイトが異なります');
        if(!hash_equals($p['integrity'],$this->integrity($dir.'/tree')))throw new OpException('準備したファイルが変更されています。もう一度確認してください');
        if($checkCurrent){$tag=$this->paths->currentTag($name);$old=$tag===null?[]:$this->scan($this->paths->assertInside($name,$this->paths->current($name)),$p['spec'],true);
            if($tag!==$p['baseline_tag']||!hash_equals($p['baseline_hash'],self::digest($old)))throw new OpException('確認後に公開中のコードが変わりました。もう一度公開前の確認を行ってください');}
        return $p;
    }
    public function cleanup(array $params=[]): array
    {
        $removed=0;
        foreach(glob($this->store->directory('preparations').'/*')?:[] as $dir){if(is_link($dir)||!preg_match('/\A[a-f0-9]{32}\z/D',basename($dir)))continue;
            $file=$dir.'/plan.json';$p=is_file($file)&&!is_link($file)?json_decode(file_get_contents($file),true):[];
            $expires=(int)($p['expires']??(filemtime($dir)+86400));if($expires>=time())continue;
            Proc::must(['/bin/rm','-rf','--',$dir],null,120);$removed++;
        }
        return ['removed'=>$removed];
    }
    public function listing(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$rows=[];
        foreach(glob($this->store->directory('preparations').'/*/plan.json')?:[] as $file){if(is_link($file))continue;$p=json_decode(file_get_contents($file),true);if(($p['service']??'')===$name&&($p['expires']??0)>time())$rows[]=$this->publicPlan($p);}
        usort($rows,static fn($a,$b)=>$b['created']<=>$a['created']);return ['preparations'=>array_slice($rows,0,20)];
    }
    public function publicPlan(array $p): array {return array_intersect_key($p,array_flip(['token','service','created','expires','baseline_tag','meta','counts','changes','warnings','options']));}
    public function details(array $params): array {return $this->publicPlan($this->load(Spec::name($params['service']['name']??''),(string)($params['token']??'')));}
    public function diff(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$token=(string)($params['token']??'');$p=$this->load($name,$token);$path=(string)($params['path']??'');
        if(!in_array($path,array_column($p['changes'],'path'),true)||self::dataPath($path,$p['spec']))throw new OpException('変更一覧からファイルを選んでください');
        $new=$this->directory($token).'/tree/'.$path;$current=$p['baseline_tag']===null?null:$this->paths->release($name,$p['baseline_tag']).'/'.$path;$values=[];
        foreach([$current,$new] as $f){if($f===null||!file_exists($f)){$values[]='';continue;}if(is_link($f)||!is_file($f)||filesize($f)>128*1024)return ['binary'=>true,'diff'=>[]];$v=file_get_contents($f);if(str_contains($v,"\0"))return ['binary'=>true,'diff'=>[]];$values[]=$v;}
        return ['binary'=>false,'diff'=>ApacheHistory::diff($values[0],$values[1])];
    }
}
