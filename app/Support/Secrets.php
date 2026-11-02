<?php
declare(strict_types=1);
namespace Pjas\Support;
final class Secrets
{
    private static function key(): string
    {
        $path=(string)Config::get('security_key',dirname((string)Config::get('db')).'/security.key');
        if(is_link($path)||is_link($path.'.lock'))throw new \RuntimeException('認証用の暗号鍵の保存先が不正です');
        if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
        $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('認証用の暗号鍵をロックできません');chmod($path.'.lock',0600);
        try{
            if(!is_file($path)){$fp=fopen($path,'x');if(!$fp)throw new \RuntimeException('認証用の暗号鍵を作成できません');chmod($path,0600);fwrite($fp,random_bytes(32));fclose($fp);}
            $key=file_get_contents($path);if(!is_string($key)||strlen($key)!==32)throw new \RuntimeException('認証用の暗号鍵を読み取れません');return $key;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function encrypt(string $plain): string {$iv=random_bytes(12);$c=openssl_encrypt($plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'pjas-totp');if($c===false)throw new \RuntimeException('認証情報を保存できません');return 'v1:'.base64_encode($iv.$tag.$c);}
    public static function decrypt(string $value): string {if(!str_starts_with($value,'v1:'))return $value;$raw=base64_decode(substr($value,3),true);if($raw===false||strlen($raw)<28)throw new \RuntimeException('認証情報が破損しています');$p=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'pjas-totp');if($p===false)throw new \RuntimeException('認証情報を復号できません');return $p;}
}
