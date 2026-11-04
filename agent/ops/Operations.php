<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{ControlStore,Notifier,Paths,Spec,Proc,OpException,GitHubApp};
require_once dirname(__DIR__).'/lib/ControlStore.php';
require_once dirname(__DIR__).'/lib/Notifier.php';
require_once dirname(__DIR__).'/lib/GitHubApp.php';
final class Operations
{
    private ControlStore $store;
    public function __construct(private array $cfg,private Paths $paths){$this->store=new ControlStore($cfg);}
    public function settings(array $params=[]): array
    {
        $s=$this->store->read('settings');$n=$s['notifications']??[];
        return ['public_url'=>$s['public_url']??'','preview_domain'=>$s['preview_domain']??'',
            'notifications'=>array_intersect_key($n,array_flip(['channel','smtp_host','smtp_port','smtp_security','smtp_user','mail_from','mail_to']))+
                ['channel'=>'none','webhook_configured'=>!empty($n['webhook_url']),'password_configured'=>!empty($n['smtp_password'])],
            'github'=>(new GitHubApp($this->cfg))->status(),'preview_user_ready'=>posix_getpwnam($this->cfg['preview_user']??'pjas-preview')!==false];
    }
    public function saveSettings(array $params): array
    {
        $old=$this->store->read('settings');$url=rtrim(trim((string)($params['public_url']??'')),'/');
        if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_QUERY)||parse_url($url,PHP_URL_FRAGMENT)||!in_array(parse_url($url,PHP_URL_PATH)??'',['','/'],true)))throw new OpException('管理画面のURLはhttps://から始まる、末尾にパスのないアドレスを入力してください');
        $domain=trim((string)($params['preview_domain']??''));if($domain!=='')Spec::domain($domain);
        $channel=(string)($params['channel']??'none');if(!in_array($channel,['none','webhook','discord','smtp'],true))throw new OpException('通知方法を選んでください');
        $n=array_replace($old['notifications']??[],['channel'=>$channel]);
        if(in_array($channel,['webhook','discord'],true)){
            $target=trim((string)($params['webhook_url']??''))?:($old['notifications']['webhook_url']??'');
            if(!filter_var($target,FILTER_VALIDATE_URL)||parse_url($target,PHP_URL_SCHEME)!=='https'||parse_url($target,PHP_URL_USER)||preg_match('/[\r\n]/',$target))throw new OpException('通知先はhttps://から始まるWebhook URLを入力してください');if($channel==='discord'&&!Notifier::validateDiscord($target))throw new OpException('Discordのサーバー設定 → 連携サービス → ウェブフックで発行したURLを入力してください');$n['webhook_url']=$target;
        }
        if($channel==='smtp'){
            $host=trim((string)($params['smtp_host']??''));if(!preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/D',$host))throw new OpException('メールサーバー名を確認してください');
            $port=filter_var($params['smtp_port']??587,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if(!$port)throw new OpException('メールサーバーのポートが不正です');
            $security=(string)($params['smtp_security']??'starttls');if(!in_array($security,['starttls','ssl'],true))throw new OpException('メールの暗号化方法を選んでください');
            foreach(['mail_from','mail_to'] as $field)if(!filter_var($params[$field]??'',FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$params[$field]))throw new OpException('送信元・通知先のメールアドレスを確認してください');
            $user=(string)($params['smtp_user']??'');$password=(string)($params['smtp_password']??'')?:($old['notifications']['smtp_password']??'');
            if(strlen($user)>200||strlen($password)>1024||preg_match('/[\r\n\0]/',$user.$password))throw new OpException('メール認証情報が不正です');
            $n=array_replace($n,['smtp_host'=>$host,'smtp_port'=>$port,'smtp_security'=>$security,'smtp_user'=>$user,'smtp_password'=>$password,'mail_from'=>$params['mail_from'],'mail_to'=>$params['mail_to']]);
        }
        $this->store->write('settings',array_replace($old,['public_url'=>$url,'preview_domain'=>$domain,'notifications'=>$n]));return $this->settings();
    }
    public function notify(array $params): array
    {
        $text=(string)($params['message']??'pjas-ctrlの通知テストです。');if(strlen($text)>10000)throw new OpException('通知内容が長すぎます');return (new Notifier($this->cfg))->send($text);
    }
    private function size(string $path): array
    {
        if(!is_dir($path)||is_link($path))return ['bytes'=>0,'partial'=>false];$bytes=0;$count=0;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $f){if(++$count>200000)return ['bytes'=>$bytes,'partial'=>true];if(!$f->isLink()&&$f->isFile())$bytes+=$f->getSize();}return ['bytes'=>$bytes,'partial'=>false];
    }
    public function resources(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$root=$this->cfg['www_root'];
        $out=['free'=>disk_free_space($root),'total'=>disk_total_space($root)];
        if(!empty($params['details']))foreach(['releases'=>$this->paths->releases($name),'shared'=>$this->paths->shared($name),'backups'=>$this->store->directory('backups/'.$name)] as $key=>$dir)$out[$key]=$this->size($dir);
        return $out;
    }
    public static function matchesDomain(string $pattern,string $domain): bool
    {
        $pattern=strtolower(rtrim($pattern,'.'));$domain=strtolower(rtrim($domain,'.'));
        if(str_starts_with($pattern,'*.'))return substr_count($pattern,'.')===substr_count($domain,'.')&&str_ends_with($domain,substr($pattern,1));return hash_equals($pattern,$domain);
    }
    public function certificate(array $params): array
    {
        $domain=Spec::domain($params['service']['domain']??'');$file=$this->cfg['tls_cert_file']??'/etc/ssl/cloudflare/memo.takusumi.work.pem';
        if(!is_file($file)||!($pem=file_get_contents($file))||!($cert=openssl_x509_parse($pem)))throw new OpException('Cloudflare証明書を読み取れません。サーバーの証明書設定を確認してください');
        $names=[];foreach(explode(',',$cert['extensions']['subjectAltName']??'') as $part)if(preg_match('/^DNS:(.+)$/',trim($part),$m))$names[]=$m[1];if(!$names&&!empty($cert['subject']['CN']))$names[]=$cert['subject']['CN'];
        $matches=false;foreach($names as $pattern)if(self::matchesDomain($pattern,$domain))$matches=true;
        return ['domain'=>$domain,'names'=>$names,'matches'=>$matches,'valid_from'=>$cert['validFrom_time_t'],'expires'=>$cert['validTo_time_t'],'days'=>(int)floor(($cert['validTo_time_t']-time())/86400),
            'valid'=>$matches&&$cert['validFrom_time_t']<=time()&&$cert['validTo_time_t']>time(),'issuer'=>$cert['issuer']['O']??$cert['issuer']['CN']??''];
    }
    private function tail(string $file,string $query,bool $unprivileged=false): string
    {
        if(!is_file($file)||!is_readable($file))return 'まだログがありません。';
        if($unprivileged){$r=Proc::run(['/usr/bin/python3',dirname(__DIR__).'/lib/read_text.py',$file,'131072','tail'],null,20,$this->cfg['web_user'],['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);if($r['code']!==0)throw new OpException('ログを実行ユーザーの権限で読み取れません');$lines=preg_split('/\R/',$r['out']);if($query!=='')$lines=array_filter($lines,static fn($l)=>stripos($l,$query)!==false);return self::redact(implode("\n",array_slice($lines,-300)));}
        $fp=fopen($file,'rb');$size=filesize($file);if($size>128*1024)fseek($fp,-128*1024,SEEK_END);$body=stream_get_contents($fp);fclose($fp);
        $lines=preg_split('/\R/',$body);if($size>128*1024)array_shift($lines);
        if($query!=='')$lines=array_values(array_filter($lines,static fn($l)=>stripos($l,$query)!==false));
        $text=implode("\n",array_slice($lines,-300));
        return self::redact($text);
    }
    private static function redact(string $text): string
    {
        return preg_replace(['~(Authorization:\s*(?:Bearer|Basic)\s+)\S+~i','~((?:password|token|secret|api_key)\s*[=:]\s*)[^\s,;]+~i','~https://[^\s/@]+:[^\s/@]+@~'],['$1[非表示]','$1[非表示]','https://[非表示]@'],$text);
    }
    public function logs(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$source=(string)($params['source']??'apache-error');$query=substr((string)($params['query']??''),0,150);
        if($source==='daemon'){
            $unit=(string)($params['unit']??'');$found=false;foreach((new Daemon($this->cfg))->listing(['service'=>$name])['daemons'] as $d)if($d['unit']===$unit)$found=true;
            if(!$found)throw new OpException('このサイトに関連付けられた常駐プログラムを選んでください');
            $r=Proc::run(['/usr/bin/journalctl','--no-pager','-n','300','--output=short-iso','--unit',$unit],null,15);$lines=preg_split('/\R/',$r['out']);if($query!=='')$lines=array_filter($lines,static fn($l)=>stripos($l,$query)!==false);return ['content'=>self::redact(substr(implode("\n",$lines),-128*1024))];
        }
        $apache=rtrim($this->cfg['apache_log_dir']??'/var/log/apache2','/');
        if(in_array($source,['apache-error','apache-access'],true)){
            $file=$apache.'/'.$name.($source==='apache-error'?'-error.log':'-access.log');
            $conf=rtrim($this->cfg['apache_sites'],'/').'/pjas-'.$name.'.conf';$directive=$source==='apache-error'?'ErrorLog':'CustomLog';
            if(is_file($conf)&&!is_link($conf)){
                preg_match_all('~^[\t ]*'.$directive.'[\t ]+(?:"([^"\r\n]+)"|([^\s]+))~mi',file_get_contents($conf),$matches,PREG_SET_ORDER);
                foreach($matches as $match){$candidate=str_replace('${APACHE_LOG_DIR}',$apache,$match[1]?:($match[2]??''));$real=realpath($candidate);$root=realpath($apache);
                    if($real&&$root&&str_starts_with($real,$root.'/')&&!is_link($candidate)&&is_file($real)){$file=$real;break;}}
            }
        }
        elseif($source==='app'){
            $log=(string)($params['file']??'app.log');if(!preg_match('/\A[a-zA-Z0-9_.-]{1,100}\.log\z/D',$log))throw new OpException('ログ名が不正です');$file=$this->paths->shared($name).'/logs/'.$log;
            if(file_exists($file))$this->paths->assertInside($name,$file);
        }elseif($source==='cron'){
            $id=Spec::taskId($params['task_id']??null);$script=rtrim($this->cfg['cron_script_dir']??'/var/lib/pjas/cron','/').'/task-'.$id.'.sh';
            if(!is_file($script)||is_link($script)||!str_contains(file_get_contents($script),escapeshellarg($this->paths->current($name))))throw new OpException('このサイトの定期処理を選んでください');
            $file=rtrim($this->cfg['cron_log_dir']??'/var/log/pjas/cron','/').'/task-'.$id.'.log';
        }else throw new OpException('ログの種類を選んでください');
        if(is_link($file))throw new OpException('ログの保存先が不正です');
        return ['content'=>$this->tail($file,$query,in_array($source,['app','cron'],true))];
    }
}
