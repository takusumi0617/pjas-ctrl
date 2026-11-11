<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{ComponentPolicy,ControlStore,OpException,Proc,Spec,SiteTls,Paths};

/** TLS private keys stay on this host. Certificate engines are administrator adapters. */
final class Certificates
{
    private ControlStore $store;
    public function __construct(private array $cfg) { $this->store=new ControlStore($cfg); }
    private function site(array $p): string { return Spec::name(is_array($p['service']??null)?$p['service']['name']??'':$p['service']??''); }
    public static function validate(string $certificate,string $key,string $domain,string $trust,int $minimum=3600): array
    {
        ComponentPolicy::safe($trust);
        $cert=openssl_x509_read((string)file_get_contents($certificate)); $private=openssl_pkey_get_private((string)file_get_contents($key));
        if (!$cert || !$private || !openssl_x509_check_private_key($cert,$private)) throw new OpException('TLS証明書と秘密鍵が一致しません');
        $info=openssl_x509_parse($cert); $now=time();
        if (!$info || $info['validFrom_time_t']>$now || $info['validTo_time_t']<$now+$minimum) throw new OpException('TLS証明書が有効期間外です');
        // Require SAN (CN-only certificates are not accepted), verify chain, purpose and hostname.
        if (empty($info['extensions']['subjectAltName'])) throw new OpException('TLS証明書にSANがありません');
        $r=Proc::run(['/usr/bin/openssl','verify','-purpose','sslserver','-verify_hostname',Spec::domain($domain),'-CAfile',$trust,'-untrusted',$certificate,$certificate],null,20);
        if ($r['code']!==0) throw new OpException('TLS証明書のSAN・チェーン・用途を検証できません');
        return ['fingerprint'=>openssl_x509_fingerprint($cert,'sha256'),'expires'=>gmdate('c',$info['validTo_time_t'])];
    }
    private function configured(string $site,string $provider): array
    {
        $policy=new ComponentPolicy($this->cfg); $r=$policy->grant('tls_providers',$provider,$site);
        if (!in_array($r['type']??null,['common','manual','pjas-cert'],true)) throw new OpException('証明書の取得方式が不正です');
        return [$policy,$r];
    }
    public function plan(array $p): array
    {
        $site=$this->site($p); $provider=(string)($p['provider']??''); [$policy,$r]=$this->configured($site,$provider);
        $old=$this->store->read('tls-'.$site); $review=hash('sha256',json_encode([$site,$provider,$r,$old],JSON_THROW_ON_ERROR));
        return ['review'=>$review,'provider'=>$provider,'mode'=>$r['type'],'current'=>$old,'private_key_exported'=>false];
    }
    private function command(array $argv,array $replacements=[]): void
    {
        if (!$argv || !array_is_list($argv) || count($argv)>64) throw new OpException('証明書アダプターが未設定です');
        foreach ($argv as &$arg) { if (!is_string($arg)||preg_match('/[\x00-\x1f]/',$arg)) throw new OpException('アダプター定義が不正です'); $arg=strtr($arg,$replacements); } unset($arg);
        $real=realpath($argv[0]); if (!$real || !is_executable($real)) throw new OpException('証明書アダプターがありません'); ComponentPolicy::safe($real);
        $r=Proc::run($argv,null,120,null,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        if ($r['code']!==0) throw new OpException('証明書アダプターの実行に失敗しました');
    }
    public function apply(array $p): array
    {
        $site=$this->site($p); $path=$this->cfg['apache_sites'].'/.pjas-deploy-'.$site.'.lock';
        if (is_link($path)) throw new OpException('サイトロックが不正です');
        $lock=fopen($path,'c'); if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new OpException('サイトは作業中です');
        try { return $this->applyLocked($site,$p); } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function applyLocked(string $site,array $p): array
    {
        $plan=$this->plan($p);
        if (!hash_equals($plan['review'],(string)($p['review']??''))) throw new OpException('証明書適用を再レビューしてください');
        $provider=(string)$p['provider']; [$policy,$r]=$this->configured($site,$provider);
        $grant=$policy->data['sites'][$site]??null;
        if (!$grant) throw new OpException('サイトの許可が未登録です');
        $domain=Spec::domain($grant['domain']);
        if (in_array($domain,$policy->data['control_domains']??[],true)) throw new OpException('管理画面自身のHTTPSはこの連携で変更できません');
        $spec=Spec::service(['name'=>$site,'domain'=>$domain,'runtime'=>$grant['runtime'],'docroot'=>$grant['document_root'],'tls'=>1]);
        $old=$this->store->read('tls-'.$site); $dir=$this->store->directory('tls/'.$site); $current=$dir.'/current'; $previous=is_link($current)?readlink($current):null;
        ComponentPolicy::safe($r['trust']??'');
        $generation=bin2hex(random_bytes(16)); $new=$dir.'/'.$generation; mkdir($new,0700); $meta=[];
        $vhost=new Vhost($this->cfg,new Paths($this->cfg['www_root']));
        $confPath=$vhost->confPath($site); $priorConf=is_file($confPath)?(string)file_get_contents($confPath):null;
        if ($priorConf!==null) ComponentPolicy::safe($confPath);
        $enabledPath=$vhost->enabledDir().'/pjas-'.$site.'.conf'; $wasEnabled=file_exists($enabledPath);
        try {
            if ($r['type']==='common') {
                $meta=self::validate($this->cfg['tls_cert_file'],$this->cfg['tls_key_file'],$domain,$r['trust']);
                $this->store->write('tls-'.$site,['provider'=>'common','time'=>gmdate('c')]);
            } else {
                if ($r['type']==='manual') {
                    foreach (['certificate','key','trust'] as $key) ComponentPolicy::safe($r[$key]??'');
                    if ((stat($r['key'])['mode']&0077)!==0) throw new OpException('TLS秘密鍵は0600にしてください');
                    copy($r['certificate'],$new.'/certificate.pem'); copy($r['key'],$new.'/key.pem'); chmod($new.'/key.pem',0600);
                } else {
                    // Only CSR is handed to the adapter; private key is not a placeholder.
                    $this->command(['/usr/bin/openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:3072','-out',$new.'/key.pem']); chmod($new.'/key.pem',0600);
                    $this->command(['/usr/bin/openssl','req','-new','-key',$new.'/key.pem','-subj','/CN='.$domain,'-addext','subjectAltName=DNS:'.$domain,'-out',$new.'/request.csr']);
                    $request=$r['request']??[];
                    if (!in_array('{csr}',$request,true) || !in_array('{certificate}',$request,true)) throw new OpException('アダプターはCSRと証明書出力先の引数が必要です');
                    $this->command($request,['{csr}'=>$new.'/request.csr','{certificate}'=>$new.'/certificate.pem','{domain}'=>$domain]);
                }
                ComponentPolicy::safe($new.'/certificate.pem');
                $meta=self::validate($new.'/certificate.pem',$new.'/key.pem',$domain,$r['trust'],max(3600,(int)($r['min_validity']??3600)));
                $this->store->write('tls-'.$site,['provider'=>$provider,'generation'=>$generation,'time'=>gmdate('c'),'policy'=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR))]+$meta);
                $link=$dir.'/.switch-'.bin2hex(random_bytes(8)); if (!symlink($generation,$link)||!rename($link,$current)) throw new OpException('証明書を切り替えられません');
            }
            // Vhost applies configtest and reload with its own rollback handling.
            $vhost->apply(['service'=>$spec]);
            // Live SNI connection validates the actually served leaf, chain and hostname.
            $port=(int)($r['probe_port']??443); if ($port<1||$port>65535) throw new OpException('TLS検査ポートが不正です');
            $context=stream_context_create(['ssl'=>['peer_name'=>$domain,'verify_peer'=>true,'verify_peer_name'=>true,'cafile'=>$r['trust'],'SNI_enabled'=>true,'capture_peer_cert'=>true]]);
            $fp=@stream_socket_client('tls://127.0.0.1:'.$port,$errno,$error,10,STREAM_CLIENT_CONNECT,$context);
            if (!$fp) throw new OpException('SNI付き実接続で証明書を検証できません');
            $params=stream_context_get_params($fp); fclose($fp);
            $fingerprint=openssl_x509_fingerprint($params['options']['ssl']['peer_certificate'],'sha256');
            if ($meta && !hash_equals($meta['fingerprint'],$fingerprint)) throw new OpException('実際の配信証明書が一致しません');
            $this->audit($site,'applied',$p); return ['provider'=>$provider,'verified'=>true]+$meta;
        } catch (\Throwable $e) {
            $this->store->write('tls-'.$site,$old?:['provider'=>'common']);
            if ($previous!==null) { $link=$dir.'/.restore-'.bin2hex(random_bytes(8)); symlink($previous,$link); rename($link,$current); }
            else @unlink($current);
            $restored=false;
            try {
                if ($priorConf===null) @unlink($confPath);
                else { $tmp=$confPath.'.restore-'.bin2hex(random_bytes(8)); file_put_contents($tmp,$priorConf,LOCK_EX); chmod($tmp,0644); rename($tmp,$confPath); }
                if (!$wasEnabled) Proc::must([$this->cfg['a2dissite'],'-q','pjas-'.$site],null,20);
                $vhost->reload(); $restored=true;
            } catch (\Throwable) {}
            $this->audit($site,$restored?'failed-restored':'failed-restore-failed',$p);
            throw new OpException($restored?'証明書の適用に失敗し、直前の配置へ復旧しました':'証明書の適用と復旧に失敗しました。管理者が確認してください');
        }
    }
    private function audit(string $site,string $result,array $p): void
    {
        $rows=$this->store->read('tls-audit-'.$site); $rows[]=['time'=>gmdate('c'),'actor'=>substr((string)($p['_actor']??'cli'),0,120),'result'=>$result];
        $this->store->write('tls-audit-'.$site,array_slice($rows,-200));
    }
    public function renew(array $p): array
    {
        $site=$this->site($p); $s=$this->store->read('tls-'.$site);
        if (empty($s['provider']) || $s['provider']==='common') return ['skipped'=>true];
        [$policy,$r]=$this->configured($site,$s['provider']);
        if ($r['type']!=='pjas-cert' || empty($r['auto_renew'])) return ['skipped'=>true];
        if (($s['policy']??'')!==hash('sha256',json_encode($r,JSON_THROW_ON_ERROR))) throw new OpException('自動更新の許可設定が変わりました。再レビューしてください');
        if (strtotime($s['expires']??'')>time()+max(3600,(int)($r['renew_before']??604800))) return ['skipped'=>true];
        $p['provider']=$s['provider']; $plan=$this->plan($p); $p['review']=$plan['review'];
        return $this->apply($p);
    }
    public function status(array $p): array
    {
        $site=$this->site($p); return ['selection'=>$this->store->read('tls-'.$site,['provider'=>'common']),'audit'=>$this->store->read('tls-audit-'.$site)];
    }
}
