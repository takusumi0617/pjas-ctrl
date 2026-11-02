<?php
declare(strict_types=1);
namespace Pjas\Agentd;

final class EncryptedFile
{
    private static function read($fp,int $n): string
    {
        $out='';while(strlen($out)<$n&&!feof($fp)){ $part=fread($fp,$n-strlen($out));if($part===false)throw new OpException('バックアップを読み込めません');$out.=$part; }
        if(strlen($out)!==$n)throw new OpException('バックアップが途中で切れています');return $out;
    }
    public static function encrypt(string $source,string $destination,string $key): void
    {
        $in=fopen($source,'rb');$out=fopen($destination,'xb');if(!$in||!$out)throw new OpException('暗号化ファイルを作成できません');chmod($destination,0600);
        try{
            fwrite($out,"PJASBK2\n");$i=0;
            while(true){$data=fread($in,1024*1024);if($data===false)throw new OpException('バックアップを読み込めません');$end=$data===''&&feof($in);$flag=$end?"\0":"\1";$iv=random_bytes(12);
                $cipher=openssl_encrypt($data,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'pjas-backup:'.$i.':'.ord($flag));if($cipher===false)throw new OpException('バックアップを暗号化できません');
                $record=$flag.$iv.$tag.$cipher;if(fwrite($out,pack('N',strlen($record)).$record)!==strlen($record)+4)throw new OpException('バックアップを保存できません');$i++;if($end)break;
            }
        }finally{fclose($in);fclose($out);}
    }
    public static function decrypt(string $source,string $destination,string $key): void
    {
        $in=fopen($source,'rb');$out=fopen($destination,'xb');if(!$in||!$out)throw new OpException('バックアップを開けません');chmod($destination,0600);
        try{
            if(self::read($in,8)!=="PJASBK2\n")throw new OpException('バックアップの形式が不正です');$i=0;
            while(true){$length=unpack('N',self::read($in,4))[1];if($length<29||$length>1048605)throw new OpException('バックアップが破損しています');$record=self::read($in,$length);$flag=ord($record[0]);if($flag>1)throw new OpException('バックアップが破損しています');
                $plain=openssl_decrypt(substr($record,29),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($record,1,12),substr($record,13,16),'pjas-backup:'.$i.':'.$flag);
                if($plain===false)throw new OpException('バックアップの認証に失敗しました。鍵やファイルを確認してください');
                if($flag===0){if($plain!==''||fread($in,1)!=='')throw new OpException('バックアップが破損しています');break;}
                if(fwrite($out,$plain)!==strlen($plain))throw new OpException('復元用ファイルを保存できません');$i++;
            }
        }finally{fclose($in);fclose($out);}
    }
}
