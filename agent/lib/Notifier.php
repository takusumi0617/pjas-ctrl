<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once __DIR__.'/ControlStore.php';
final class Notifier
{
    public function __construct(private array $cfg,private $transport=null){}
    public function send(string $message): array
    {
        $store=new ControlStore($this->cfg);$s=$store->read('settings')['notifications']??['channel'=>'none'];
        if(($s['channel']??'none')==='none')return ['sent'=>false,'reason'=>'通知先が未設定です'];
        if($this->transport)return ($this->transport)($s,$message);
        if(in_array($s['channel'],['webhook','discord'],true)){
            $dir=$store->directory('requests').'/notify-'.bin2hex(random_bytes(8));mkdir($dir,0700);
            try{
                file_put_contents($dir.'/curl','url = '.json_encode($s['webhook_url'],JSON_UNESCAPED_SLASHES)."\n");chmod($dir.'/curl',0600);
                file_put_contents($dir.'/body',json_encode(self::payload($s['channel'],$message),JSON_THROW_ON_ERROR));chmod($dir.'/body',0600);
                $r=Proc::run(['/usr/bin/curl','--silent','--show-error','--max-time','20','--proto','=https','--config',$dir.'/curl','--request','POST','--header','Content-Type: application/json','--data-binary','@'.$dir.'/body','--output','/dev/null','--write-out','%{http_code}'],null,25);
                if($r['code']!==0||(int)$r['out']<200||(int)$r['out']>=300)throw new OpException('通知先が受信できませんでした（HTTP '.(int)$r['out'].'）');return ['sent'=>true];
            }finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
        }
        return $this->smtp($s,$message);
    }
    public static function payload(string $channel,string $message): array
    {
        return $channel==='discord' ? ['content'=>mb_substr($message,0,950),'allowed_mentions'=>['parse'=>[]]] : ['text'=>$message,'source'=>'pjas-ctrl'];
    }
    public static function validateDiscord(string $url): bool
    {
        return (bool)preg_match('~\Ahttps://discord\.com/api/webhooks/[0-9]{10,25}/[A-Za-z0-9_-]{20,200}\z~D',$url);
    }
    private function smtp(array $s,string $message): array
    {
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$s['smtp_host'],'SNI_enabled'=>true]]);
        $fp=@stream_socket_client(($s['smtp_security']==='ssl'?'ssl':'tcp').'://'.$s['smtp_host'].':'.$s['smtp_port'],$errno,$err,15,STREAM_CLIENT_CONNECT,$context);
        if(!$fp)throw new OpException('メールサーバーに接続できません');stream_set_timeout($fp,15);
        $read=static function(array $codes)use($fp):void{$lines='';for($i=0;$i<100;$i++){ $line=fgets($fp,4096);if($line===false)throw new OpException('メールサーバーから応答がありません');$lines.=$line;if(isset($line[3])&&$line[3]===' ')break;}if(!in_array((int)substr($lines,-strlen($line),3),$codes,true))throw new OpException('メールサーバーが送信を受け付けませんでした');};
        $command=static function(string $cmd,array $codes)use($fp,$read):void{fwrite($fp,$cmd."\r\n");$read($codes);};
        try{
            $read([220]);$command('EHLO pjas-ctrl',[250]);
            if($s['smtp_security']==='starttls'){$command('STARTTLS',[220]);if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new OpException('メールサーバーと暗号化通信できません');$command('EHLO pjas-ctrl',[250]);}
            if(($s['smtp_user']??'')!==''){$command('AUTH LOGIN',[334]);$command(base64_encode($s['smtp_user']),[334]);$command(base64_encode($s['smtp_password']),[235]);}
            $command('MAIL FROM:<'.$s['mail_from'].'>',[250]);$command('RCPT TO:<'.$s['mail_to'].'>',[250,251]);$command('DATA',[354]);
            $body=chunk_split(base64_encode($message),76,"\r\n");
            fwrite($fp,'From: '.$s['mail_from']."\r\nTo: ".$s['mail_to']."\r\nSubject: =?UTF-8?B?".base64_encode('pjas-ctrl サイトのお知らせ')."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$body."\r\n.\r\n");$read([250]);$command('QUIT',[221]);return ['sent'=>true];
        }finally{fclose($fp);}
    }
}
