<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
require_once dirname(__DIR__,2).'/app/Domain/PjasManifest.php';
require_once dirname(__DIR__,2).'/app/Domain/GitHubRepository.php';
require_once dirname(__DIR__).'/lib/ComponentPolicy.php';
use Pjas\Agentd\{ComponentPolicy,ControlStore,OpException,Proc,Spec};
use Pjas\Domain\PjasManifest;

/** Isolated v2 workflow. No root command, path or user is accepted from the web. */
final class Components
{
    private ControlStore $store;
    public function __construct(private array $cfg) { $this->store = new ControlStore($cfg); }
    private function site(array $p): string { return Spec::name(is_array($p['service'] ?? null) ? $p['service']['name'] ?? '' : $p['service'] ?? ''); }
    private function state(string $site): array { return $this->store->read('components-'.$site); }
    /** Boot uses the same validation and readiness checks as an explicit start.
     * Never replay setup or resume a stopped/restored/partially initialized CA.
     */
    public function recoverReadySites(): array
    {
        $result=[];
        foreach (glob($this->store->directory().'/components-*.json')?:[] as $file) {
            $site=Spec::name(substr(basename($file),11,-5));
            try {
                $result[$site]=$this->locked($site,function()use($site){
                    $s=$this->state($site);
                    if (($s['phase']??'')!=='ready' || empty($s['initialized'])) return ['skipped'=>true];
                    // actionUnlocked validates policy, approved file hashes, code,
                    // runtime secrets and dependency health before starting.
                    return $this->actionUnlocked($site,['action'=>'start','_actor'=>'boot']);
                });
            } catch (\Throwable $e) {
                // Do not print definitions, secret values or subprocess output.
                $result[$site]=['failed'=>true];
            }
        }
        return $result;
    }
    public static function protectedSite(array $cfg, string $site): bool
    {
        $path = rtrim($cfg['control_dir'] ?? '/var/lib/pjas-control','/').'/components-'.Spec::name($site).'.json';
        if (is_file($path)) return true;
        $catalog = $cfg['component_catalog'] ?? '/etc/pjas/components.json';
        if (!is_file($catalog)) return false;
        $data = json_decode((string)file_get_contents($catalog), true);
        return isset($data['sites'][$site]);
    }
    public static function guard(array $cfg, string $site, string $op): void
    {
        if (!self::protectedSite($cfg,$site)) {
            if (str_starts_with($op,'site.')) {
                $registry=($cfg['daemon_dir']??'/var/lib/pjas-daemons').'/registry.json';
                $rows=is_file($registry)?json_decode((string)file_get_contents($registry),true):[];
                foreach ($rows??[] as $row) if (($row['service']??'')===$site && ComponentPolicy::reservedUnit($cfg,$row['unit']??'')) throw new OpException('隔離unitとの既存の関連付けを管理者が整理してください');
            }
            return;
        }
        if (str_starts_with($op,'components.') || str_starts_with($op,'tls.') || in_array($op,['health.check','apache.render','apache.reload'],true)) return;
        throw new OpException('隔離サイトはコンポーネント専用の操作を使用してください。共通ユーザー経由の操作は禁止されています');
    }
    private function locked(string $site, callable $fn): array
    {
        // Same lock as legacy deployment; configuration and publication cannot race.
        $path = $this->cfg['apache_sites'].'/.pjas-deploy-'.$site.'.lock';
        if (is_link($path)) throw new OpException('サイトロックが不正です');
        $f = fopen($path,'c');
        if (!$f || !flock($f,LOCK_EX|LOCK_NB)) throw new OpException('サイトは別の作業中です');
        try { return $fn(); } finally { flock($f,LOCK_UN); fclose($f); }
    }
    private function audit(string $site, string $action, array $p, string $outcome): void
    {
        $rows=$this->store->read('component-audit-'.$site);
        $rows[]=['time'=>gmdate('c'),'actor'=>substr((string)($p['_actor']??'cli'),0,120),'action'=>$action,'outcome'=>$outcome];
        $this->store->write('component-audit-'.$site,array_slice($rows,-500));
    }
    private function save(string $site, array $s): void { $this->store->write('components-'.$site,$s); }
    private function policy(): ComponentPolicy { return new ComponentPolicy($this->cfg); }
    private function catalogSite(ComponentPolicy $policy, string $site): array
    {
        $s=$policy->data['sites'][$site]??null;
        if (!is_array($s)) throw new OpException('管理者によるサイト許可がありません');
        return $s;
    }
    private function unit(string $site, string $id): string { return 'pjas-component-'.$site.'-'.$id.'.service'; }
    private function base(string $site): string { return rtrim($this->cfg['www_root'],'/').'/'.$site; }
    private function runtime(string $site, string $id): string
    {
        return rtrim($this->cfg['component_run_dir']??'/run/pjas-components','/').'/'.$site.'-'.$id;
    }
    private function unitPath(string $unit): string { return rtrim($this->cfg['daemon_unit_dir']??'/etc/systemd/system','/').'/'.$unit; }
    private function fpmPath(string $site, string $id): string { return $this->runtime($site,$id).'/php-fpm.conf'; }
    private function write(string $path,string $body,int $mode=0600): void
    {
        if (is_link($path)) throw new OpException('設定ファイルがリンクです');
        ComponentPolicy::safe(dirname($path));
        $tmp=$path.'.'.bin2hex(random_bytes(8));
        if (file_put_contents($tmp,$body,LOCK_EX)===false || !chmod($tmp,$mode) || !rename($tmp,$path)) throw new OpException('設定を書き込めません');
    }
    private function executable(string $path): void
    {
        // Distribution binaries are often symlinks; resolve then verify the immutable target.
        $real=realpath($path);
        if (!$real || !is_executable($real)) throw new OpException('必要な実行ファイルがありません');
        ComponentPolicy::safe(dirname($path));
        if (is_link($path) && !in_array(lstat($path)['uid'],[0,posix_geteuid()],true)) throw new OpException('実行ファイルへのリンクが保護されていません');
        ComponentPolicy::safe($real);
    }
    /** Only immutable Git objects from a previously inspected commit are used. */
    private function source(string $site,string $token,ComponentPolicy $policy): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$token)) throw new OpException('読み込みトークンが不正です');
        $dir=rtrim($this->cfg['github_repo_dir']??'/var/lib/pjas-repositories','/').'/previews/'.$token;
        ComponentPolicy::safe($dir); ComponentPolicy::safe($dir.'.json');
        $origin=json_decode((string)file_get_contents($dir.'.json'),true,32,JSON_THROW_ON_ERROR);
        $grant=$this->catalogSite($policy,$site);
        if (\Pjas\Domain\GitHubRepository::normalize($origin['repo']??'')!==\Pjas\Domain\GitHubRepository::normalize($grant['repository']??'')) throw new OpException('許可されたリポジトリではありません');
        $sha=$origin['sha']??'';
        if (!preg_match('/\A[a-f0-9]{40}\z/D',$sha)) throw new OpException('固定コミットが不正です');
        $bytes=trim(Proc::must(['/usr/bin/git','cat-file','-s',$sha.':.pjas'],$dir,30));
        if (!ctype_digit($bytes) || (int)$bytes>131072) throw new OpException('.pjasは128KB以内にしてください');
        $json=Proc::must(['/usr/bin/git','show',$sha.':.pjas'],$dir,30);
        $m=PjasManifest::parse($json);
        if ($m['version']!==2 || $m['site']['name']!==$site) throw new OpException('v2の対象サイトが一致しません');
        foreach (['domain','runtime','document_root'] as $key) if (($m['site'][$key]??null)!==($grant[$key]??null)) throw new OpException('公開先・ランタイムが管理者の許可と一致しません');
        if (isset($grant['https']) && ($m['site']['https']??true)!==$grant['https']) throw new OpException('HTTPS設定が許可と一致しません');
        return ['checkout'=>$dir,'sha'=>$sha,'manifest'=>$m];
    }
    private function checkManifest(ComponentPolicy $policy,string $site,array $m,bool $checkSecrets=true): array
    {
        $grant=$this->catalogSite($policy,$site); $rows=[]; $used=[];
        foreach ($m['requirements'] as $id) {
            $r=$policy->grant('requirements',$id,$site);
            $this->runDefinition($r,'環境要件');
        }
        foreach ($m['components'] as $c) {
            $profile=$policy->profile($c['execution_profile'],$site);
            if ($profile['type']!==$c['type'] || isset($used[$c['execution_profile']])) throw new OpException('コンポーネントごとに専用の対応プロファイルが必要です');
            $used[$c['execution_profile']]=true;
            foreach ($c['command'] as $arg) if (str_contains($arg,'..')) throw new OpException('起動引数の親パス参照は禁止です');
            if ($c['command']) {
                if (!in_array($c['command'][0],$profile['executables']??[],true)) throw new OpException('実行ファイルはプロファイルで許可してください');
                $this->executable($c['command'][0]);
            }
            $storage=[]; $secrets=[];
            foreach ($c['storage'] as $id) $storage[$id]=$policy->storage($id,$site,$c['execution_profile']);
            foreach ($c['secrets'] as $id) $secrets[$id]=$policy->secret($id,$site,$c['execution_profile'],$checkSecrets);
            foreach ($c['setup'] as $id) {
                $op=$policy->grant('setup',$id,$site);
                if (($op['profile']??'')!==$c['execution_profile']) throw new OpException('初期化処理の実行対象が不正です');
                foreach (['command','check'] as $key) $this->definition($op[$key]??[]);
            }
            foreach ($c['health_checks'] as $id) { $h=$policy->grant('health',$id,$site); if (($h['profile']??'')!==$c['execution_profile']) throw new OpException('ヘルスチェックの実行対象が不正です'); $this->definition($h['command']??[]); }
            if (!$c['health_checks']) throw new OpException('各コンポーネントの準備完了確認が必要です');
            if ($c['type']==='php') {
                $this->executable($profile['fpm_binary']??'');
                $apache=$profile['apache_user']??'www-data';
                if (!preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D',$apache) || !posix_getpwnam($apache)) throw new OpException('Apacheユーザーが不正です');
            }
            $existing=null;
            if (isset($c['existing_service'])) {
                $existing=$policy->grant('existing_services',$c['existing_service'],$site);
                if (($existing['profile']??'')!==$c['execution_profile'] || !preg_match('/\A[a-zA-Z0-9-]+\.service\z/D',$existing['unit']??'') || str_starts_with($existing['unit'],'pjas-agent')) throw new OpException('既存サービスの割り当てが不正です');
                if (($existing['sites']??[])!==[$site]) throw new OpException('既存サービスは1サイト専用にしてください');
                $legacy=($this->cfg['daemon_dir']??'/var/lib/pjas-daemons').'/registry.json';
                $linked=is_file($legacy)?json_decode((string)file_get_contents($legacy),true):[];
                if (isset($linked[$existing['unit']])) throw new OpException('既存の共通デーモン関連付けを管理者が整理してください');
                if ($c['command']) throw new OpException('既存サービスの起動内容は上書きできません');
                // Exact installed definition hash is administrator-approved, including drop-ins.
                $text=Proc::must([$this->cfg['systemctl'],'cat','--',$existing['unit']],null,15);
                if (!hash_equals($existing['sha256']??'',hash('sha256',$text))) throw new OpException('既存サービスの承認済み定義が変わっています');
                $info=\Pjas\Agentd\DaemonDefinition::parse($text,$profile['user']);
                if (!$info['editable'] || $info['user']!==$profile['user']) throw new OpException('既存サービスの実行ユーザーが不一致です');
                foreach (['NoNewPrivileges=yes','ProtectSystem=strict','UMask=0077'] as $setting) if (!str_contains($text,$setting)) throw new OpException('既存サービスの隔離設定が不足しています');
            }
            $rows[$c['id']]=['declaration'=>$c,'profile'=>$profile,'storage'=>$storage,'secrets'=>$secrets,'existing'=>$existing];
        }
        if (isset($m['backup_policy'])) $policy->grant('backup_policies',$m['backup_policy'],$site);
        return $rows;
    }
    private function definition(array $argv): void
    {
        if (!$argv || !array_is_list($argv) || count($argv)>64) throw new OpException('管理者の操作定義が不正です');
        foreach ($argv as $arg) if (!is_string($arg) || preg_match('/[\x00-\x1f]/',$arg)) throw new OpException('管理者の操作引数が不正です');
        $this->executable($argv[0]);
    }
    private function runDefinition(array $r,string $label): void
    {
        $this->definition($r['command']??[]);
        $out=Proc::run($r['command'],null,min(60,max(1,(int)($r['timeout']??10))),$r['user']??null,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        if ($out['code']!==0) throw new OpException($label.'の確認に失敗しました'); // do not expose command output
    }
    private function fingerprint(): string { return hash('sha256',json_encode($this->policy()->data,JSON_THROW_ON_ERROR)); }
    public function plan(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $policy=$this->policy(); $src=$this->source($site,(string)($p['token']??''),$policy);
            $rows=$this->checkManifest($policy,$site,$src['manifest'],false);
            $s=$this->state($site); $files=$this->render($site,$rows);
            $review=['sha'=>$src['sha'],'manifest'=>$src['manifest'],'files'=>$files,'previous'=>$s['revision']??null,'policy'=>$this->fingerprint()];
            $hash=hash('sha256',json_encode($review,JSON_THROW_ON_ERROR));
            $this->store->write('component-plan-'.$site,['hash'=>$hash,'source'=>$src,'review'=>$review,'expires'=>time()+1800]);
            $pending=[]; foreach ($rows as $id=>$row) foreach ($row['secrets'] as $ref=>$secret) if (!empty($secret['pending'])) $pending[]=$ref;
            return ['missing_secrets'=>$pending,'review'=>$hash,'sha'=>$src['sha'],'previous'=>$s['manifest']??null,'proposed'=>$src['manifest'],'files'=>$files,'state'=>$s['phase']??'unregistered'];
        });
    }
    private function render(string $site,array $rows): array
    {
        $files=[]; $q=[ComponentPolicy::class,'quote'];
        foreach ($rows as $id=>$r) {
            if ($r['existing']) continue;
            $c=$r['declaration']; $pr=$r['profile']; $run=$this->runtime($site,$id);
            $code=$this->base($site).'/current'; $cwd=$code.($c['working_directory']!==''?'/'.$c['working_directory']:'');
            $unit="[Unit]\nDescription=PJAS isolated component\nAfter=network.target\n";
            // Readiness is enforced by start(), not merely After=.
            foreach ($c['depends_on'] as $dep) {
                $depUnit=$rows[$dep]['existing']['unit']??$this->unit($site,$dep);
                $unit.="After={$depUnit}\nRequires={$depUnit}\n";
            }
            $unit.="[Service]\nType=simple\nUser={$pr['user']}\nGroup={$pr['group']}\nSupplementaryGroups=\nWorkingDirectory=".str_replace('%','%%',$cwd)."\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\nPrivateDevices=yes\nProtectKernelTunables=yes\nProtectKernelModules=yes\nProtectControlGroups=yes\nRestrictSUIDSGID=yes\nLockPersonality=yes\nCapabilityBoundingSet=\nAmbientCapabilities=\nUMask=0077\nRestart=on-failure\nRestartSec=5\nStandardOutput=null\nStandardError=null\n";
            foreach ($this->policy()->data['storage']??[] as $other) if (!in_array($other['path']??'',array_column($r['storage'],'path'),true)) {
                ComponentPolicy::safe($other['path'],true);
                $unit.='InaccessiblePaths='.$q($other['path'])."\n";
            }
            $unit.='ReadOnlyPaths='.$q($this->base($site))."\nReadWritePaths=".$q($run.'/sessions').' '.$q($run.'/tmp').' '.$q($run.'/socket')."\n";
            foreach ($r['storage'] as $storageId=>$st) {
                $unit.='ReadWritePaths='.$q($st['path'])."\nEnvironment=".$q('PJAS_STORAGE_'.strtoupper(str_replace('-','_',$storageId)).'='.$st['path'])."\n";
            }
            // A private root-managed directory is readable by this profile only.
            foreach ($r['secrets'] as $secretId=>$st) $unit.='Environment='.$q('PJAS_SECRET_'.strtoupper(str_replace('-','_',$secretId)).'='.$run.'/secrets/'.$secretId)."\n";
            if ($c['type']==='php') {
                $fpm="[global]\ndaemonize = no\nerror_log = /dev/null\npid = {$run}/tmp/fpm.pid\n[{$site}-{$id}]\nuser = {$pr['user']}\ngroup = {$pr['group']}\nlisten = {$run}/socket/php.sock\nlisten.mode = 0600\nlisten.acl_users = ".($pr['apache_user']??'www-data')."\npm = ondemand\npm.max_children = 8\npm.process_idle_timeout = 10s\nclear_env = yes\ncatch_workers_output = no\nsecurity.limit_extensions = .php\nphp_admin_value[session.save_path] = {$run}/sessions\nphp_admin_value[sys_temp_dir] = {$run}/tmp\n";
                foreach ($r['storage'] as $sid=>$st) $fpm.='env[PJAS_STORAGE_'.strtoupper(str_replace('-','_',$sid)).'] = '.$st['path']."\n";
                foreach ($r['secrets'] as $sid=>$st) $fpm.='env[PJAS_SECRET_'.strtoupper(str_replace('-','_',$sid)).'] = '.$run.'/secrets/'.$sid."\n";
                $files[$this->fpmPath($site,$id)]=$fpm;
                $argv=[$pr['fpm_binary'],'--nodaemonize','--fpm-config',$this->fpmPath($site,$id)];
            } else $argv=array_map(fn($a)=>str_replace('{{site_root}}',$code,$a),$c['command']);
            $unit.='ExecStart='.implode(' ',array_map($q,$argv))."\n[Install]\nWantedBy=multi-user.target\n";
            $files[$this->unitPath($this->unit($site,$id))]=$unit;
        }
        return $files;
    }
    public function apply(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($p,$site){
            $plan=$this->store->read('component-plan-'.$site); $s=$this->state($site);
            if (!$plan || ($plan['expires']??0)<time() || !hash_equals($plan['hash'],(string)($p['review']??''))) throw new OpException('プレビューの再確認が必要です');
            if (($s['revision']??null)!==$plan['review']['previous'] || $this->fingerprint()!==$plan['review']['policy']) throw new OpException('設定が競合しています。再プレビューしてください');
            $policy=$this->policy();
            try { $rows=$this->checkManifest($policy,$site,$plan['source']['manifest']); }
            catch (OpException $e) {
                if (!$s && $e->getMessage()==='秘密情報の保護ファイルが未配置です') $this->save($site,['phase'=>'secrets_waiting','revision'=>null,'policy'=>$plan['review']['policy'],'manifest'=>$plan['source']['manifest'],'source'=>$plan['source'],'files'=>[],'units'=>[],'setup'=>[],'releases'=>[],'initialized'=>false]);
                throw $e;
            }
            if ($s && $this->anyRunning($site,$s['manifest'])) throw new OpException('設定更新前に全コンポーネントを停止してください');
            $files=$this->render($site,$rows); $old=[];
            foreach ($s['files']??[] as $path=>$hash) if (!is_file($path) || hash_file('sha256',$path)!==$hash) throw new OpException('管理外の設定変更と競合しています');
            $next=$s+['setup'=>[],'releases'=>[]];
            if (($s['manifest']??null)!==$plan['source']['manifest']) $next['initialized']=false;
            $next['phase']='registered'; $next['manifest']=$plan['source']['manifest']; $next['source']=$plan['source'];
            $next['units']=[]; foreach ($rows as $id=>$r) $next['units'][$id]=$this->unitFor($site,$id,$r);
            $next['revision']=$plan['hash']; $next['policy']=$plan['review']['policy']; $next['files']=[];
            // Persist ownership before external effects so legacy operations remain blocked after failure.
            $this->save($site,$next);
            try {
                foreach ($rows as $id=>$r) $this->prepareRuntime($site,$id,$r);
                foreach ($files as $path=>$body) { $old[$path]=is_file($path)?file_get_contents($path):null; $this->write($path,$body,0644); $next['files'][$path]=hash('sha256',$body); }
                foreach ($s['files']??[] as $path=>$_) if (!isset($files[$path])) { $old[$path]=file_get_contents($path); unlink($path); }
                foreach ($rows as $id=>$r) if ($r['declaration']['type']==='php') {
                    $out=Proc::run([$r['profile']['fpm_binary'],'--test','--fpm-config',$this->fpmPath($site,$id)],null,20,$r['profile']['user']);
                    if ($out['code']!==0) throw new OpException('サイト別PHP-FPM設定を検証できません（ACL対応も確認してください）');
                }
                Proc::must([$this->cfg['systemctl'],'daemon-reload'],null,20);
                foreach ($s['manifest']['components']??[] as $oldComponent) if (!isset($rows[$oldComponent['id']])) {
                    $secretDir=$this->runtime($site,$oldComponent['id']).'/secrets';
                    if (is_dir($secretDir)) {
                        ComponentPolicy::safe($secretDir);
                        foreach (glob($secretDir.'/*')?:[] as $copy) if (is_file($copy)||is_link($copy)) unlink($copy);
                    }
                }
                $next['phase']='dependencies_checked'; $this->save($site,$next); $this->audit($site,'apply',$p,'ok');
            } catch (\Throwable $e) {
                foreach ($old as $path=>$body) { if ($body===null) @unlink($path); else $this->write($path,$body,0644); }
                Proc::run([$this->cfg['systemctl'],'daemon-reload'],null,20);
                $next['phase']='configuration_failed'; $next['files']=$s['files']??[]; $this->save($site,$next); $this->audit($site,'apply',$p,'failed'); throw $e;
            }
            return ['phase'=>$next['phase'],'revision'=>$next['revision']];
        });
    }
    private function prepareRuntime(string $site,string $id,array $r): void
    {
        $root=rtrim($this->cfg['component_run_dir']??'/run/pjas-components','/');
        if (!is_dir($root) && !mkdir($root,0755)) throw new OpException('実行先を作成できません');
        ComponentPolicy::safe($root);
        $run=$this->runtime($site,$id);
        if (is_link($run)) throw new OpException('実行先がリンクです');
        if (!is_dir($run) && !mkdir($run,0750)) throw new OpException('実行先を作成できません');
        // Root owns runtime directory; only its sessions/tmp children are application-writable.
        chown($run,posix_geteuid()); chgrp($run,$r['profile']['gid']); chmod($run,0751);
        foreach (['sessions','tmp','socket'] as $dir) {
            $path=$run.'/'.$dir;
            if (is_link($path)) throw new OpException('一時領域がリンクです');
            if (!is_dir($path)) mkdir($path,0700);
            chown($path,$r['profile']['uid']); chgrp($path,$r['profile']['gid']); chmod($path,$dir==='socket'?0711:0700);
        }
        $secrets=$run.'/secrets';
        if (is_link($secrets)) throw new OpException('秘密情報の保存先がリンクです');
        if (!is_dir($secrets)) mkdir($secrets,0750);
        chown($secrets,posix_geteuid()); chgrp($secrets,$r['profile']['gid']); chmod($secrets,0750);
        foreach (glob($secrets.'/*')?:[] as $path) if (!isset($r['secrets'][basename($path)])) unlink($path);
        foreach ($r['secrets'] as $sid=>$st) {
            $path=$secrets.'/'.$sid; $this->write($path,(string)file_get_contents($st['path']),0640); chgrp($path,$r['profile']['gid']);
        }
    }

    private function assertApplied(string $site, array $s): array
    {
        if (!$s || !in_array($s['phase'],['dependencies_checked','secrets_waiting','initialized','ready','stopped','setup_failed','restore_hold'],true)) throw new OpException('先に設定を適用してください');
        if (($s['policy']??'')!==$this->fingerprint()) throw new OpException('管理者の許可設定が更新されました。再プレビュー・適用が必要です');
        $rows=$this->checkManifest($this->policy(),$site,$s['manifest']);
        $rendered=null;
        foreach ($s['files'] as $path=>$hash) {
            if (!is_file($path) && str_starts_with($path,rtrim($this->cfg['component_run_dir']??'/run/pjas-components','/').'/')) {
                $rendered??=$this->render($site,$rows);
                if (!isset($rendered[$path]) || hash('sha256',$rendered[$path])!==$hash) throw new OpException('再生成する設定が承認内容と一致しません');
                foreach ($rows as $id=>$r) $this->prepareRuntime($site,$id,$r);
                $this->write($path,$rendered[$path],0644);
            }
            if (!is_file($path) || hash_file('sha256',$path)!==$hash) throw new OpException('起動設定が承認済みの内容と一致しません');
        }
        return $rows;
    }
    private function anyRunning(string $site,array $m): bool
    {
        $policy=$this->policy();
        foreach ($m['components'] as $c) {
            $unit=isset($c['existing_service'])?$policy->grant('existing_services',$c['existing_service'],$site)['unit']:$this->unit($site,$c['id']);
            $r=Proc::run([$this->cfg['systemctl'],'is-active','--', $unit],null,10);
            if (in_array(trim($r['out']),['active','activating','deactivating','reloading'],true)) return true;
        }
        return false;
    }
    private function command(string $site,string $id,array $r,string $kind,array $argv,int $timeout=60): int
    {
        $this->definition($argv); $unit='pjas-task-'.$site.'-'.$id.'-'.bin2hex(random_bytes(6));
        $run=$this->runtime($site,$id); $props=['User='.$r['profile']['user'],'Group='.$r['profile']['group'],'SupplementaryGroups=','NoNewPrivileges=yes','ProtectSystem=strict','ProtectHome=yes','PrivateTmp=yes','PrivateDevices=yes','CapabilityBoundingSet=','UMask=0077','ReadOnlyPaths='.$this->base($site),'ReadWritePaths='.$run.'/tmp '.$run.'/sessions','StandardOutput=null','StandardError=null'];
        if (!in_array($kind,['health','check'],true)) foreach ($r['storage'] as $st) $props[]='ReadWritePaths='.$st['path'];
        else foreach ($r['storage'] as $st) $props[]='ReadOnlyPaths='.$st['path'];
        $args=[$this->cfg['systemd_run']??'/usr/bin/systemd-run','--wait','--collect','--quiet','--unit='.$unit,'--service-type=exec','--property=RuntimeMaxSec='.$timeout];
        foreach ($props as $prop) $args[]='--property='.$prop;
        foreach ($r['storage'] as $sid=>$st) $args[]='--setenv=PJAS_STORAGE_'.strtoupper(str_replace('-','_',$sid)).'='.$st['path'];
        foreach ($r['secrets'] as $sid=>$st) $args[]='--setenv=PJAS_SECRET_'.strtoupper(str_replace('-','_',$sid)).'='.$run.'/secrets/'.$sid;
        $args[]='--working-directory='.$this->base($site).'/current'; $args[]='--';
        $result=Proc::run(array_merge($args,$argv),null,$timeout+10,null,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        if ($result['code']===124) Proc::run([$this->cfg['systemctl'],'stop','--',$unit],null,15);
        return $result['code'];
    }
    public function setup(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($p,$site){
            $s=$this->state($site); $rows=$this->assertApplied($site,$s);
            if ($s['phase']==='restore_hold') throw new OpException('復元後の検査・承認が必要です');
            if ($this->anyRunning($site,$s['manifest'])) throw new OpException('初期化前に全コンポーネントを停止してください');
            if (!is_dir($this->base($site).'/current')) throw new OpException('先にコードを公開してください');
            try {
                foreach ($rows as $id=>$r) foreach ($r['declaration']['setup'] as $setup) {
                    $key=$id.'.'.$setup; $op=$this->policy()->grant('setup',$setup,$site);
                    if (isset($s['setup'][$key])) {
                        if (($s['setup'][$key]['state']??'')!=='done') throw new OpException('前回の初期化が中断または失敗しました。既存状態を管理者が確認してください');
                        if ($this->command($site,$id,$r,'check',$op['check'])!==0) throw new OpException('初期化済み状態の整合性が不明です');
                        continue;
                    }
                    // A distinct administrator-specified empty-state check is mandatory.
                    if (empty($op['absent']) || $this->command($site,$id,$r,'check',$op['absent'])!==0) throw new OpException('既存のCA・鍵の状態が不明なため初期化を停止しました');
                    $s['setup'][$key]=['execution'=>bin2hex(random_bytes(16)),'state'=>'running','time'=>gmdate('c')]; $this->save($site,$s);
                    if ($this->command($site,$id,$r,'setup',$op['command'],min(600,max(1,(int)($op['timeout']??120))))!==0 || $this->command($site,$id,$r,'check',$op['check'])!==0) throw new OpException('初期化または完了判定に失敗しました');
                    $s['setup'][$key]['state']='done'; $this->save($site,$s);
                }
                $s['initialized']=true; $s['phase']='initialized'; $this->save($site,$s); $this->audit($site,'setup',$p,'ok');
            } catch (\Throwable $e) { $s['phase']='setup_failed'; $this->save($site,$s); $this->audit($site,'setup',$p,'failed'); throw $e; }
            return ['phase'=>$s['phase'],'operations'=>$s['setup']];
        });
    }
    private function health(string $site,string $id,array $r): bool
    {
        foreach ($r['declaration']['health_checks'] as $ref) {
            $h=$this->policy()->grant('health',$ref,$site);
            if ($this->command($site,$id,$r,'health',$h['command'],min(30,max(1,(int)($h['timeout']??10))))!==0) return false;
        }
        return true;
    }
    private function unitFor(string $site,string $id,array $r): string { return $r['existing']['unit']??$this->unit($site,$id); }
    public function action(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,fn()=>$this->actionUnlocked($site,$p));
    }
    private function actionUnlocked(string $site,array $p): array
    {
            $s=$this->state($site); $action=$p['action']??'';
            if (!in_array($action,['start','stop','restart'],true)) throw new OpException('操作が不正です');
            if ($action==='stop') {
                // Persist the stop intent before systemctl: a crash midway must
                // never turn a partially stopped CA into a boot-time restart.
                if (($s['phase']??'')!=='restore_hold') $s['phase']='stopped';
                $this->save($site,$s);
                foreach (array_reverse($s['units']??[]) as $unit) Proc::must([$this->cfg['systemctl'],'stop','--',$unit],null,30);
                if (($s['phase']??'')!=='restore_hold') $s['phase']='stopped';
                $this->save($site,$s); $this->audit($site,'stop',$p,'ok'); return ['phase'=>$s['phase']];
            }
            $rows=$this->assertApplied($site,$s);
            if (!empty($s['current'])) $this->verifyCode($this->base($site).'/releases/'.$s['current']);
            if ($action!=='stop' && (empty($s['initialized']) || !in_array($s['phase'],['initialized','ready','stopped'],true))) throw new OpException('初期化・復元検査を先に完了してください');
            if ($action==='stop'||$action==='restart') foreach (array_reverse($rows,true) as $id=>$r) Proc::must([$this->cfg['systemctl'],'stop','--',$this->unitFor($site,$id,$r)],null,30);
            $started=[];
            try {
                if ($action!=='stop') foreach ($rows as $id=>$r) {
                    foreach ($r['declaration']['depends_on'] as $dep) if (!$this->health($site,$dep,$rows[$dep])) throw new OpException('依存コンポーネントの準備ができていません');
                    $versions=[];
                    foreach ($r['secrets'] as $secretId=>$secret) $versions[$secretId]=hash_file('sha256',$secret['path']);
                    if (($s['secret_versions'][$id]??[])!==$versions) {
                        $s['secret_versions'][$id]=$versions; $this->save($site,$s); $this->audit($site,'secret-refresh',$p,'applied-on-start');
                    }
                    $this->prepareRuntime($site,$id,$r);
                    Proc::must([$this->cfg['systemctl'],'start','--',$this->unitFor($site,$id,$r)],null,30); $started[]=[$id,$r];
                    $ok=false; $deadline=microtime(true)+min(60,max(1,(int)($r['profile']['ready_timeout']??10)));
                    do {
                        $active=Proc::run([$this->cfg['systemctl'],'is-active','--',$this->unitFor($site,$id,$r)],null,5);
                        if ($active['code']===0 && $this->health($site,$id,$r)) { $ok=true; break; }
                        usleep(200000);
                    } while (microtime(true)<$deadline);
                    if (!$ok) throw new OpException('コンポーネントの準備完了がタイムアウトしました: '.$id);
                }
                $s['phase']=$action==='stop'?'stopped':'ready'; $this->save($site,$s); $this->audit($site,$action,$p,'ok');
            } catch (\Throwable $e) {
                foreach (array_reverse($started) as [$id,$r]) Proc::run([$this->cfg['systemctl'],'stop','--',$this->unitFor($site,$id,$r)],null,30);
                $s['phase']='initialized'; $this->save($site,$s); $this->audit($site,$action,$p,'failed'); throw $e;
            }
            return ['phase'=>$s['phase']];
    }
    private function webSpec(string $site): array
    {
        $policy=$this->policy(); $grant=$this->catalogSite($policy,$site);
        return Spec::service(['name'=>$site,'domain'=>$grant['domain'],'runtime'=>$grant['runtime'],'docroot'=>$grant['document_root'],'tls'=>$grant['https']??true]);
    }
    public function webPlan(array $p): array
    {
        $site=$this->site($p); $s=$this->state($site); $this->assertApplied($site,$s);
        $vhost=new Vhost($this->cfg,new \Pjas\Agentd\Paths($this->cfg['www_root']));
        $preview=$vhost->preview(['service'=>$this->webSpec($site)]);
        return $preview+['review'=>hash('sha256',json_encode([$s['revision'],$preview],JSON_THROW_ON_ERROR))];
    }
    public function webApply(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $preview=$this->webPlan($p);
            if (!hash_equals($preview['review'],(string)($p['review']??''))) throw new OpException('公開設定を再レビューしてください');
            $vhost=new Vhost($this->cfg,new \Pjas\Agentd\Paths($this->cfg['www_root']));
            $result=$vhost->apply(['service'=>$this->webSpec($site)]); $this->audit($site,'vhost',$p,'ok'); return $result;
        });
    }
    public function releasePlan(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $s=$this->state($site); $this->assertApplied($site,$s);
            $src=$this->source($site,(string)($p['token']??''),$this->policy());
            if ($src['manifest']!==$s['manifest']) throw new OpException('設定変更は公開とは別にプレビュー・適用してください');
            $list=Proc::must(['/usr/bin/git','ls-tree','-r','--name-only',$src['sha']],$src['checkout'],30);
            $review=hash('sha256',json_encode([$src,$s['revision'],$list],JSON_THROW_ON_ERROR));
            $this->store->write('component-release-'.$site,['source'=>$src,'revision'=>$s['revision'],'review'=>$review,'expires'=>time()+1800]);
            return ['review'=>$review,'sha'=>$src['sha'],'files'=>explode("\n",trim($list)),'current'=>$s['current']??null];
        });
    }
    private function switchCode(string $site,string $tag): void
    {
        if (!preg_match('/\A[a-f0-9]{16}-[a-f0-9]{8}\z/D',$tag)) throw new OpException('リリースIDが不正です');
        ComponentPolicy::safe($this->base($site).'/releases/'.$tag);
        $link=$this->base($site).'/.current-'.bin2hex(random_bytes(8));
        if (!symlink('releases/'.$tag,$link) || !rename($link,$this->base($site).'/current')) throw new OpException('公開先を切り替えられません');
    }
    public function publish(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $s=$this->state($site); $this->assertApplied($site,$s);
            $plan=$this->store->read('component-release-'.$site);
            if (!$plan || ($plan['expires']??0)<time() || !hash_equals($plan['review'],(string)($p['review']??'')) || $plan['revision']!==$s['revision']) throw new OpException('公開するコミットを再レビューしてください');
            $src=$plan['source']; $base=$this->base($site);
            if (!is_dir($base) && !mkdir($base,0755)) throw new OpException('配置先を作成できません');
            ComponentPolicy::safe($base);
            if (file_exists($base.'/current') && !isset($s['current'])) throw new OpException('既存サイトの専用ユーザーへの移行は管理者による準備が必要です');
            if (!is_dir($base.'/releases')) mkdir($base.'/releases',0755);
            ComponentPolicy::safe($base.'/releases');
            $tag=substr($src['sha'],0,16).'-'.bin2hex(random_bytes(4)); $target=$base.'/releases/'.$tag;
            mkdir($target,0755);
            $tar=$this->store->directory('work').'/code-'.bin2hex(random_bytes(8)).'.tar';
            try {
                Proc::must(['/usr/bin/git','archive','--format=tar','--output='.$tar,$src['sha']],$src['checkout'],60);
                $r=Proc::run(['/usr/bin/python3',dirname(__DIR__).'/lib/component_archive.py',$tar,$target],null,120);
                if ($r['code']!==0) throw new OpException('安全にリリースを配置できません（リンク・特殊ファイル・件数制限を確認してください）');
            } finally { @unlink($tar); }
            $this->verifyCode($target);
            $restart=$this->anyRunning($site,$s['manifest']);
            if ($restart) $this->actionUnlocked($site,['action'=>'stop']+$p);
            $previous=$s['current']??null;
            try {
                $this->switchCode($site,$tag);
                $s=$this->state($site); $s['current']=$tag; $s['releases'][$tag]=['sha'=>$src['sha'],'time'=>gmdate('c')]; $this->save($site,$s);
                if ($restart) $this->actionUnlocked($site,['action'=>'start']+$p);
                $this->audit($site,'publish',$p,'ok');
            } catch (\Throwable $e) {
                $restored=false;
                if ($previous) {
                    try { $this->switchCode($site,$previous); $s=$this->state($site); $s['current']=$previous; $this->save($site,$s); if($restart)$this->actionUnlocked($site,['action'=>'start']+$p); $restored=true; } catch (\Throwable) {}
                }
                $this->audit($site,'publish',$p,$restored?'failed-code-restored':'failed-restore-failed'); throw $e;
            }
            return ['release'=>$tag,'sha'=>$src['sha'],'state_restored'=>false];
        });
    }
    private function verifyCode(string $root): void
    {
        ComponentPolicy::safe($root);
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $s=lstat($file->getPathname());
            if ($file->isLink() || (!$file->isFile() && !$file->isDir()) || $s['uid']!==posix_geteuid() || ($s['mode']&0022)) throw new OpException('コードの所有者・書き込み権限が不正です');
        }
    }
    public function rollback(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $s=$this->state($site); $this->assertApplied($site,$s); $tag=$p['release']??'';
            if (!isset($s['releases'][$tag]) || ($p['expected_current']??null)!==($s['current']??null)) throw new OpException('リリースが存在しないか公開先が変わっています');
            $this->verifyCode($this->base($site).'/releases/'.$tag);
            $old=$s['current']; $restart=$this->anyRunning($site,$s['manifest']);
            if ($restart) $this->actionUnlocked($site,['action'=>'stop']+$p);
            try {
                $this->switchCode($site,$tag); $s=$this->state($site); $s['current']=$tag; $this->save($site,$s);
                if ($restart) $this->actionUnlocked($site,['action'=>'start']+$p);
            } catch (\Throwable $e) {
                $this->switchCode($site,$old); $s=$this->state($site); $s['current']=$old; $this->save($site,$s);
                if ($restart) $this->actionUnlocked($site,['action'=>'start']+$p);
                $this->audit($site,'rollback',$p,'failed-code-restored'); throw $e;
            }
            $this->audit($site,'rollback',$p,'ok-code-only'); return ['release'=>$tag,'state_restored'=>false];
        });
    }
    private function backupPolicy(string $site,array $s): array
    {
        $policy=$this->policy(); $id=$s['manifest']['backup_policy']??null;
        if (!$id) throw new OpException('バックアップ方針を先に登録してください');
        $bp=$policy->grant('backup_policies',$id,$site);
        ComponentPolicy::safe($bp['key_file']??'');
        $keyStat=stat($bp['key_file']);
        if (($keyStat['mode']&0077) || $keyStat['uid']!==posix_geteuid() || filesize($bp['key_file'])!==32) throw new OpException('別管理の32バイト暗号鍵（0600）が必要です');
        $backupRoot=$this->store->directory('component-backups');
        if (str_starts_with($bp['key_file'],$backupRoot.'/')) throw new OpException('復号鍵をバックアップと同じ保存先に置かないでください');
        $expected=[]; foreach ($s['manifest']['components'] as $c) foreach ($c['storage'] as $ref) $expected[$ref]=$c['execution_profile'];
        if (array_diff(array_keys($expected),$bp['storage']??[]) || array_diff($bp['storage']??[],array_keys($expected))) throw new OpException('バックアップ方針に全永続領域を指定してください');
        $targets=[];
        foreach ($expected as $ref=>$profile) {
            $st=$policy->storage($ref,$site,$profile); $pr=$policy->profile($profile,$site);
            if (($st['purpose']??'')==='root-ca') throw new OpException('ルートCA鍵はオンライン保存対象にできません');
            $targets['storage-'.$ref]=['path'=>$st['path'],'uid'=>$pr['uid'],'gid'=>$pr['gid']];
            if (str_starts_with($bp['key_file'],$st['path'].'/')) throw new OpException('復号鍵をバックアップ対象に含められません');
        }
        foreach ($bp['secrets']??[] as $ref) {
            $secret=$policy->grant('secrets',$ref,$site); $secret=$policy->secret($ref,$site,$secret['profile']);
            if ($secret['path']===$bp['key_file']) throw new OpException('復号鍵をバックアップ対象に含められません');
            $targets['secret-'.$ref]=['path'=>$secret['path'],'uid'=>posix_geteuid(),'gid'=>stat($secret['path'])['gid'],'file'=>true];
        }
        if (!$targets) throw new OpException('保存対象がありません');
        return [$bp,$targets];
    }
    private function backupTool(string $mode,string $spec,string $archive): void
    {
        $r=Proc::run(['/usr/bin/python3',dirname(__DIR__).'/lib/component_backup.py',$mode,$spec,$archive],null,600);
        if ($r['code']!==0) throw new OpException('永続領域の保存・検証・復元に失敗しました。全サービスを停止したまま管理者が確認してください');
    }
    public function maintain(array $p): array
    {
        $site=$this->site($p); $s=$this->state($site);
        if (empty($s['manifest']['backup_policy']) || $s['phase']==='restore_hold') return ['skipped'=>true];
        [$bp]=$this->backupPolicy($site,$s);
        if (empty($bp['auto_backup'])) return ['skipped'=>true];
        return $this->backup(['service'=>$site,'_scheduled'=>true,'_actor'=>'monitor']);
    }
    public function backup(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $s=$this->state($site); $this->assertApplied($site,$s); [$bp,$targets]=$this->backupPolicy($site,$s);
            if (!empty($p['_scheduled']) && isset($s['backup']['time']) && strtotime($s['backup']['time'])>time()-max(3600,(int)($bp['max_age']??86400))) return ['skipped'=>true];
            $restart=$this->anyRunning($site,$s['manifest']);
            $this->actionUnlocked($site,['action'=>'stop']+$p);
            // Engine-specific prepare/check commands are administrator-owned and run unprivileged.
            $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8)); $dir=$this->store->directory('component-backups/'.$site);
            $spec=$this->store->directory('work').'/'.$id.'.json'; $tar=$spec.'.tar';
            try {
                if (isset($bp['check'])) $this->runDefinition(['command'=>$bp['check'],'user'=>$bp['check_user']??null],'停止後の整合性');
                $this->write($spec,json_encode($targets,JSON_THROW_ON_ERROR));
                $this->backupTool('snapshot',$spec,$tar);
                \Pjas\Agentd\EncryptedFile::encrypt($tar,$dir.'/'.$id.'.pjas',(string)file_get_contents($bp['key_file']));
                $meta=['id'=>$id,'site'=>$site,'time'=>gmdate('c'),'policy'=>$s['policy'],'targets'=>array_keys($targets),'sha256'=>hash_file('sha256',$dir.'/'.$id.'.pjas'),'consistent'=>true,'restore_tested'=>false];
                $this->write($dir.'/'.$id.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
                $s=$this->state($site); $s['backup']=$meta; $this->save($site,$s); $this->audit($site,'backup',$p,'ok');
                if ($restart) $this->actionUnlocked($site,['action'=>'start']+$p);
                return $meta;
            } catch (\Throwable $e) { $this->audit($site,'backup',$p,'failed-stopped'); throw $e; }
            finally { @unlink($spec); @unlink($tar); }
        });
    }
    private function backupMeta(string $site,string $id): array
    {
        if (!preg_match('/\A[0-9]{14}-[a-f0-9]{16}\z/D',$id)) throw new OpException('バックアップIDが不正です');
        $path=$this->store->directory('component-backups/'.$site).'/'.$id;
        ComponentPolicy::safe($path.'.json'); ComponentPolicy::safe($path.'.pjas');
        $m=json_decode((string)file_get_contents($path.'.json'),true,32,JSON_THROW_ON_ERROR);
        if (($m['site']??'')!==$site || hash_file('sha256',$path.'.pjas')!==$m['sha256']) throw new OpException('バックアップの対象または整合性が不正です');
        return [$m,$path];
    }
    public function restorePlan(array $p): array
    {
        $site=$this->site($p); $s=$this->state($site); [$meta]=$this->backupMeta($site,(string)($p['backup']??''));
        $review=hash('sha256',json_encode([$meta,$s['revision']],JSON_THROW_ON_ERROR));
        return ['review'=>$review,'backup'=>$meta,'stops_all_components'=>true,'issuance_held_after_restore'=>true,'history_loss_possible'=>true,'code_unchanged'=>true];
    }
    public function restore(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $review=$this->restorePlan($p);
            if (!hash_equals($review['review'],(string)($p['review']??''))) throw new OpException('復元内容を再レビューしてください');
            $s=$this->state($site); $this->assertApplied($site,$s); [$bp,$targets]=$this->backupPolicy($site,$s);
            [$meta,$path]=$this->backupMeta($site,(string)$p['backup']);
            if ($meta['policy']!==$s['policy'] || $meta['targets']!==array_keys($targets)) throw new OpException('復元時の権限・対象が保存時と異なります');
            $spec=$this->store->directory('work').'/restore-'.bin2hex(random_bytes(8)); $tar=$spec.'.tar'; $held=false;
            try {
                $this->write($spec,json_encode($targets,JSON_THROW_ON_ERROR));
                \Pjas\Agentd\EncryptedFile::decrypt($path.'.pjas',$tar,(string)file_get_contents($bp['key_file']));
                $this->backupTool('validate',$spec,$tar);
                $this->actionUnlocked($site,['action'=>'stop']+$p);
                // Persist issuance hold BEFORE the first state mutation. Never restart automatically.
                $s=$this->state($site); $s['phase']='restore_hold'; $s['restore']=['backup'=>$meta['id'],'time'=>gmdate('c'),'verified'=>false]; $this->save($site,$s); $held=true;
                $this->backupTool('restore',$spec,$tar);
                if (empty($bp['verify_restore'])) throw new OpException('エンジンのDB・鍵・証明書整合性検査を登録してください');
                $this->runDefinition(['command'=>$bp['verify_restore'],'user'=>$bp['check_user']??null],'復元後の整合性');
                $s['restore']['verified']=true; $this->save($site,$s);
                $meta['restore_tested']=true; $this->write($path.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
                $this->audit($site,'restore',$p,'verified-issuance-held'); return ['phase'=>'restore_hold','verified'=>true];
            } catch (\Throwable $e) { $this->audit($site,'restore',$p,$held?'failed-issuance-held':'failed-before-state-change'); throw $e; }
            finally { @unlink($spec); @unlink($tar); }
        });
    }
    public function resume(array $p): array
    {
        $site=$this->site($p);
        return $this->locked($site,function()use($site,$p){
            $s=$this->state($site); $this->assertApplied($site,$s); [$bp]=$this->backupPolicy($site,$s);
            if ($s['phase']!=='restore_hold' || empty($s['restore']['verified']) || ($p['accept_history_loss']??false)!==true) throw new OpException('復元検証と発行・失効履歴欠落の確認が必要です');
            if (empty($bp['verify_restore'])) throw new OpException('復元検査が未設定です');
            $this->runDefinition(['command'=>$bp['verify_restore'],'user'=>$bp['check_user']??null],'復元後の整合性');
            $s['phase']='initialized'; $s['initialized']=true; $s['restore']['accepted']=gmdate('c'); $this->save($site,$s);
            $this->audit($site,'resume',$p,'history-risk-accepted'); return ['phase'=>'initialized'];
        });
    }
    public function status(array $p): array
    {
        $site=$this->site($p); $s=$this->state($site);
        if (!$s) return ['phase'=>'unregistered','components'=>[]];
        $result=['releases'=>$s['releases']??[],'current'=>$s['current']??null,'phase'=>$s['phase'],'revision'=>$s['revision'],'sha'=>$s['source']['sha'],'setup'=>$s['setup'],'components'=>[],'backup'=>$s['backup']??null,'restore_hold'=>$s['phase']==='restore_hold','audit'=>$this->store->read('component-audit-'.$site)];
        try {
            $rows=$this->assertApplied($site,$s);
            foreach ($rows as $id=>$r) {
                $active=Proc::run([$this->cfg['systemctl'],'is-active','--',$this->unitFor($site,$id,$r)],null,5);
                $checks=[]; foreach ($r['declaration']['health_checks'] as $ref) {
                    $h=$this->policy()->grant('health',$ref,$site);
                    $checks[$ref]=$active['code']===0 && $this->command($site,$id,$r,'health',$h['command'],min(30,max(1,(int)($h['timeout']??10))))===0;
                }
                $versions=[]; foreach ($r['secrets'] as $ref=>$secret) $versions[$ref]=hash_file('sha256',$secret['path']);
                $result['components'][$id]=['restart_required'=>($s['secret_versions'][$id]??[])!==$versions,'active'=>$active['code']===0,'ready'=>$active['code']===0 && !in_array(false,$checks,true),'checks'=>$checks,'profile'=>$r['declaration']['execution_profile']];
            }
            $result['configured']=true;
        } catch (\Throwable $e) { $result['configured']=false; $result['reason']='依存環境・権限・秘密情報・設定の再確認が必要です'; }
        if (isset($s['manifest']['backup_policy'])) {
            $bp=$this->policy()->grant('backup_policies',$s['manifest']['backup_policy'],$site);
            $result['backup_fresh']=isset($s['backup']['time']) && strtotime($s['backup']['time'])>time()-max(3600,(int)($bp['max_age']??86400));
        }
        return $result;
    }
}
