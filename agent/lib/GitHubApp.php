<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once __DIR__.'/ControlStore.php';
require_once dirname(__DIR__,2).'/app/Domain/GitHubRepository.php';

final class GitHubApp
{
    private ControlStore $store;
    public function __construct(private array $cfg, private $transport=null) { $this->store=new ControlStore($cfg); }
    public static function jwt(string $appId,string $pem,?int $now=null): string
    {
        $now??=time();$b64=static fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
        $payload=$b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.$b64(json_encode(['iat'=>$now-60,'exp'=>$now+540,'iss'=>$appId]));
        if(!openssl_sign($payload,$signature,$pem,OPENSSL_ALGO_SHA256))throw new OpException('GitHub Appの秘密鍵で署名できません');
        return $payload.'.'.$b64($signature);
    }
    public function settings(): array { return $this->store->read('github-app'); }
    public function save(array $in): array
    {
        $old=$this->settings();$id=trim((string)($in['app_id']??''));
        if(!preg_match('/\A[0-9]{1,20}\z/D',$id))throw new OpException('GitHub AppのApp IDを数字で入力してください');
        $pem=trim((string)($in['private_key']??''))?:($old['private_key']??'');
        $key=openssl_pkey_get_private($pem);$details=$key?openssl_pkey_get_details($key):false;
        if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048)throw new OpException('GitHub Appで発行したRSA秘密鍵（PEM）を入力してください');
        $secret=trim((string)($in['webhook_secret']??''))?:($old['webhook_secret']??bin2hex(random_bytes(32)));
        if(strlen($secret)<32||strlen($secret)>256)throw new OpException('Webhookの合言葉は32〜256文字にしてください');
        $slug=trim((string)($in['slug']??''));if(!preg_match('/\A[a-z0-9-]{1,100}\z/D',$slug))throw new OpException('GitHub Appの公開名を入力してください');
        $this->store->write('github-app',['app_id'=>$id,'private_key'=>$pem,'webhook_secret'=>$secret,'slug'=>$slug]);
        return $this->status();
    }
    public function status(): array
    {
        $s=$this->settings();return ['configured'=>!empty($s['app_id'])&&!empty($s['private_key']), 'app_id'=>$s['app_id']??'',
            'slug'=>$s['slug']??'', 'webhook_configured'=>!empty($s['webhook_secret']), 'install_url'=>empty($s['slug'])?'':'https://github.com/apps/'.$s['slug'].'/installations/new'];
    }
    private function api(string $method,string $path,string $bearer,?array $body=null): array
    {
        if($this->transport)return ($this->transport)($method,$path,$bearer,$body);
        $dir=$this->store->directory('requests').'/'.bin2hex(random_bytes(12));mkdir($dir,0700);
        try{
            $headers=$dir.'/headers';file_put_contents($headers,($bearer!==''?"Authorization: Bearer {$bearer}\n":"")."Accept: application/vnd.github+json\nX-GitHub-Api-Version: 2022-11-28\nUser-Agent: pjas-ctrl\nContent-Type: application/json\n");chmod($headers,0600);
            $args=['/usr/bin/curl','--silent','--show-error','--max-time','30','--proto','=https','--request',$method,'--header','@'.$headers,'--output',$dir.'/body','--write-out','%{http_code}','https://api.github.com'.$path];
            if($body!==null){file_put_contents($dir.'/input',json_encode($body,JSON_THROW_ON_ERROR));chmod($dir.'/input',0600);array_push($args,'--data-binary','@'.$dir.'/input');}
            $r=Proc::run($args,null,35);$code=(int)$r['out'];
            if($r['code']!==0||$code<200||$code>=300)throw new OpException('GitHubとの認証・通信に失敗しました（HTTP '.$code.'）。Appのインストール先とContents読み取り権限を確認してください');
            $out=json_decode((string)file_get_contents($dir.'/body'),true,512,JSON_THROW_ON_ERROR);return $out;
        }finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
    }
    public function convert(array $params): array
    {
        $code=(string)($params['code']??'');if(!preg_match('/\A[a-zA-Z0-9_-]{20,200}\z/D',$code))throw new OpException('GitHubからの設定コードが不正です');
        $s=$this->api('POST','/app-manifests/'.rawurlencode($code).'/conversions','');
        return $this->save(['app_id'=>(string)($s['id']??''),'private_key'=>$s['pem']??'','webhook_secret'=>$s['webhook_secret']??'','slug'=>$s['slug']??'']);
    }
    public function test(array $params): array
    {
        $repo=\Pjas\Domain\GitHubRepository::normalize((string)($params['repo']??''));$this->token($repo);return ['ok'=>true,'repo'=>$repo];
    }
    public function token(string $repo): string
    {
        if(!preg_match('~\Ahttps://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?\z~D',$repo,$m))throw new OpException('GitHubのリポジトリURLが不正です');
        $s=$this->settings();if(empty($s['app_id'])||empty($s['private_key']))throw new OpException('先に「接続設定」でGitHub Appを登録してください');
        $jwt=self::jwt($s['app_id'],$s['private_key']);
        $install=$this->api('GET','/repos/'.rawurlencode($m[1]).'/'.rawurlencode($m[2]).'/installation',$jwt);
        $id=(string)($install['id']??'');if(!preg_match('/\A[0-9]+\z/D',$id))throw new OpException('このリポジトリへのGitHub Appのインストールを確認できません');
        $result=$this->api('POST','/app/installations/'.$id.'/access_tokens',$jwt,['repositories'=>[$m[2]],'permissions'=>['contents'=>'read']]);
        $token=$result['token']??'';if(!is_string($token)||strlen($token)<10||preg_match('/[\r\n\0]/',$token))throw new OpException('GitHub Appの一時認証情報を取得できません');
        return $token;
    }
    public function withCredentials(string $repo,callable $work): array
    {
        $token=$this->token($repo);$dir=$this->store->directory('requests').'/'.bin2hex(random_bytes(12));mkdir($dir,0700);
        try{
            file_put_contents($dir.'/token',$token);chmod($dir.'/token',0600);
            file_put_contents($dir.'/askpass',"#!/bin/sh\ncase \"\$1\" in *sername*) printf '%s\\n' x-access-token;; *) cat -- \"\$PJAS_GITHUB_TOKEN_FILE\";; esac\n");chmod($dir.'/askpass',0700);
            $env=GitRepositories::publicEnv();$env['GIT_ASKPASS']=$dir.'/askpass';$env['PJAS_GITHUB_TOKEN_FILE']=$dir.'/token';
            $result=$work($env);$result['err']=str_replace($token,'[非表示]',(string)($result['err']??''));$result['out']=str_replace($token,'[非表示]',(string)($result['out']??''));return $result;
        }finally{@unlink($dir.'/token');@unlink($dir.'/askpass');@rmdir($dir);}
    }
    public function verifyRequest(array $params): array
    {
        if(empty($params['path']))return $this->verifyWebhook((string)($params['body']??''),(string)($params['signature']??''),(string)($params['event']??''),(string)($params['delivery']??''));
        $path=(string)$params['path'];$real=realpath($path);$root=realpath($this->cfg['upload_dir']);
        if(!$real||!$root||dirname($real)!==$root||is_link($path)||!preg_match('/\A[a-f0-9]{32}\.hook\z/D',basename($real))||filesize($real)>25*1024*1024)throw new OpException('Webhookの一時ファイルが不正です');
        try{return $this->verifyWebhook((string)file_get_contents($real),(string)($params['signature']??''),(string)($params['event']??''),(string)($params['delivery']??''));}finally{@unlink($real);}
    }
    public function verifyWebhook(string $body,string $signature,string $event,string $delivery): array
    {
        $secret=$this->settings()['webhook_secret']??'';
        if($secret===''||!preg_match('/\Asha256=[a-f0-9]{64}\z/D',$signature)||!hash_equals('sha256='.hash_hmac('sha256',$body,$secret),$signature))throw new OpException('Webhookの署名を確認できません');
        if(!preg_match('/\A[a-zA-Z0-9-]{1,100}\z/D',$delivery))throw new OpException('Webhookの識別番号が不正です');
        if($event==='ping')return ['ping'=>true,'delivery'=>$delivery];
        if($event!=='push')return ['ignored'=>true,'delivery'=>$delivery];
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if(!empty($data['deleted']))return ['ignored'=>true,'delivery'=>$delivery];
        $repo=\Pjas\Domain\GitHubRepository::normalize((string)($data['repository']['full_name']??''));
        $ref=(string)($data['ref']??'');$sha=(string)($data['after']??'');
        if(!str_starts_with($ref,'refs/heads/'))return ['ignored'=>true,'delivery'=>$delivery];
        if(!preg_match('/\A[a-f0-9]{40}\z/D',$sha))throw new OpException('Webhookの公開バージョンが不正です');
        return ['delivery'=>$delivery,'repo'=>$repo,'branch'=>substr($ref,11),'sha'=>$sha];
    }
}
