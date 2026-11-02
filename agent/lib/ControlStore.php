<?php
declare(strict_types=1);
namespace Pjas\Agentd;

final class ControlStore
{
    public function __construct(private array $cfg) {}
    public function root(): string { return rtrim($this->cfg['control_dir'] ?? '/var/lib/pjas-control','/'); }
    public function directory(string $part = ''): string
    {
        if (!preg_match('~\A[a-zA-Z0-9_./-]*\z~D',$part) || str_contains($part,'..')) throw new OpException('保存先が不正です');
        $root=$this->root();
        if (is_link($root)) throw new OpException('管理情報の保存先が不正です');
        if (!is_dir($root) && !mkdir($root,0700,true)) throw new OpException('管理情報の保存先を作成できません');
        $stat=stat($root);
        if ($stat['uid']!==posix_geteuid() || ($stat['mode']&0077)) throw new OpException('管理情報の保存先はroot所有・0700にしてください');
        $dir=$root;
        foreach (array_filter(explode('/',$part)) as $item) {
            $dir.='/'.$item;
            if (is_link($dir)) throw new OpException('管理情報の保存先が不正です');
            if (!is_dir($dir)&&!mkdir($dir,0700)) throw new OpException('管理情報の保存先を作成できません');
        }
        return $dir;
    }
    public function read(string $key, array $default=[]): array
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9.-]{0,100}\z/D',$key)) throw new OpException('管理情報の名前が不正です');
        $file=$this->directory().'/'.$key.'.json';
        if (is_link($file)) throw new OpException('管理情報の保存先が不正です');
        if (!is_file($file)) return $default;
        return json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    }
    public function write(string $key,array $value): void
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9.-]{0,100}\z/D',$key)) throw new OpException('管理情報の名前が不正です');
        $file=$this->directory().'/'.$key.'.json';$temp=$file.'.tmp-'.bin2hex(random_bytes(8));
        if (file_put_contents($temp,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) throw new OpException('管理情報を保存できません');
        chmod($temp,0600);
        if (!rename($temp,$file)) { @unlink($temp);throw new OpException('管理情報を保存できません'); }
    }
    public function lock(string $name)
    {
        if (!preg_match('/\A[a-z0-9.-]+\z/D',$name)) throw new OpException('ロック名が不正です');
        $path=$this->directory('locks').'/'.$name;
        if (is_link($path)) throw new OpException('ロックが不正です');
        $fp=fopen($path,'c');if(!$fp||!flock($fp,LOCK_EX))throw new OpException('操作をロックできません');return $fp;
    }
    public function key(): string
    {
        $lock=$this->lock('backup-key');
        try{$path=$this->directory().'/backup.key';
        if(is_link($path))throw new OpException('暗号鍵の保存先が不正です');
        if(!is_file($path)){ $fp=fopen($path,'x');if(!$fp)throw new OpException('暗号鍵を作成できません');chmod($path,0600);fwrite($fp,random_bytes(32));fclose($fp); }
        $key=file_get_contents($path);if(strlen($key)!==32)throw new OpException('暗号鍵が破損しています');return $key;}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
