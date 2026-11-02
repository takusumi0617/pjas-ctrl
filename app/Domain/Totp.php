<?php
declare(strict_types=1);
namespace Pjas\Domain;
final class Totp
{
    private const ABC='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public static function secret(): string
    {
        $bits='';foreach(unpack('C*',random_bytes(20)) as $byte)$bits.=str_pad(decbin($byte),8,'0',STR_PAD_LEFT);$s='';foreach(str_split($bits,5) as $part)$s.=self::ABC[bindec(str_pad($part,5,'0'))];return $s;
    }
    private static function decode(string $secret): string
    {
        $secret=strtoupper(str_replace(' ','',$secret));if(!preg_match('/\A[A-Z2-7]{16,64}\z/D',$secret))throw new \InvalidArgumentException('認証アプリの設定が不正です');$bits='';foreach(str_split($secret) as $c)$bits.=str_pad(decbin(strpos(self::ABC,$c)),5,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,8) as $part)if(strlen($part)===8)$out.=chr(bindec($part));return $out;
    }
    public static function code(string $secret,int $step): string
    {
        $hash=hash_hmac('sha1',pack('N2',(int)floor($step/4294967296),$step&0xffffffff),self::decode($secret),true);$o=ord($hash[19])&15;$n=unpack('N',substr($hash,$o,4))[1]&0x7fffffff;return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
    }
    public static function matchStep(string $secret,string $code,?int $now=null,int $last=-1): ?int
    {
        if(!preg_match('/\A[0-9]{6}\z/D',$code))return null;$step=(int)floor(($now??time())/30);
        for($i=-1;$i<=1;$i++)if($step+$i>$last&&hash_equals(self::code($secret,$step+$i),$code))return $step+$i;return null;
    }
}
