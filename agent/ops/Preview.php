<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{ControlStore,Paths,Spec,Proc,OpException,ReleaseReview,JobLog};
require_once dirname(__DIR__).'/lib/ReleaseReview.php';
require_once __DIR__.'/Backups.php';
final class Preview
{
    private ControlStore $store;
    public function __construct(private array $cfg,private Paths $paths){$this->store=new ControlStore($cfg);}
    private function name(string $name): string {return 'preview-'.substr(hash('sha256',$name),0,12);}
    public function save(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$env=(string)($params['environment']??'');
        if(strlen($env)>65536||str_contains($env,"\0"))throw new OpException('試験用の環境設定は64KB以内にしてください');
        if(empty($params['isolated_database']))throw new OpException('試験用DBの設定であることを確認してください');
        $this->store->write('preview-config-'.$name,['environment'=>$env,'confirmed'=>true]);return ['saved'=>true];
    }
    public function status(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$m=$this->store->read('preview-state-'.$name);$s=$this->store->read('preview-config-'.$name);
        return ['configured'=>!empty($s['confirmed']),'created'=>!empty($m),'url'=>$m['url']??'','created_at'=>$m['created_at']??'','username'=>'preview','source'=>$m['source']??'',
            'user_ready'=>posix_getpwnam($this->cfg['preview_user']??'pjas-preview')!==false];
    }
    private function password(string $name): array
    {
        $user='preview';$password=bin2hex(random_bytes(12));$base=rtrim($this->cfg['preview_root']??'/var/www/pjas-previews','/').'/'.$this->name($name);
        if(!is_dir($base)||is_link($base))throw new OpException('先に試験環境を作成してください');
        $file=$base.'/.htpasswd';file_put_contents($file,$user.':'.password_hash($password,PASSWORD_BCRYPT)."\n");chown($file,posix_geteuid());chgrp($file,$this->cfg['web_group']);chmod($file,0640);
        return ['username'=>$user,'password'=>$password];
    }
    public function credentials(array $params): array {return $this->password(Spec::name($params['service']['name']??''));}
    public function publish(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$lock=$this->store->lock('preview-'.$name);
        try{return $this->publishUnlocked($params);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function publishUnlocked(array $params): array
    {
        $prod=Spec::service($params['service']??null);$name=$prod['name'];$settings=$this->store->read('settings');$config=$this->store->read('preview-config-'.$name);
        if(empty($settings['preview_domain']))throw new OpException('接続設定で試験公開用の親ドメインを設定してください');
        if(empty($config['confirmed']))throw new OpException('先にこのサイトの試験用DB・環境設定を保存してください');
        $user=$this->cfg['preview_user']??'pjas-preview';if(!posix_getpwnam($user))throw new OpException('試験環境専用ユーザーがありません。接続設定に表示する初回準備を実行してください');
        $root=rtrim($this->cfg['preview_root']??'/var/www/pjas-previews','/');if(is_link($root))throw new OpException('試験環境の保存先が不正です');if(!is_dir($root))mkdir($root,0755,true);
        $id=$this->name($name);$base=$root.'/'.$id;if(is_link($base))throw new OpException('試験環境の保存先が不正です');
        if(!is_dir($base))mkdir($base,0755);$stat=stat($base);if($stat['uid']!==posix_geteuid()||($stat['mode']&0022))throw new OpException('試験環境の管理フォルダはroot所有にしてください');
        $domain=Spec::domain('preview-'.$name.'.'.$settings['preview_domain']);
        $certificate=(new Operations($this->cfg,$this->paths))->certificate(['service'=>['domain'=>$domain]]);
        if(!$certificate['valid'])throw new OpException('試験公開URLに対応する有効な証明書がありません');
        $review=new ReleaseReview($this->cfg,$this->paths);$plan=$review->load($name,(string)($params['token']??''));
        $existingConf=rtrim($this->cfg['apache_sites'],'/').'/pjas-'.$id.'.conf';
        if(is_link($existingConf)||(is_file($existingConf)&&!str_starts_with(file_get_contents($existingConf),'# pjas-ctrl isolated preview')))throw new OpException('試験用ではない公開設定は変更できません');
        $unitFile=($this->cfg['daemon_unit_dir']??'/etc/systemd/system').'/pjas-'.$id.'.service';
        if(is_link($unitFile)||(is_file($unitFile)&&!str_contains(file_get_contents($unitFile),'Description=pjas isolated preview '.$id)))throw new OpException('試験用ではない常駐プログラムは変更できません');
        if(is_file($unitFile))Proc::must([$this->cfg['systemctl'],'stop','pjas-'.$id.'.service'],null,45);
        $stagePaths=new Paths($root);$tag=gmdate('Ymd-His');$rel=$stagePaths->release($id,$tag);if(file_exists($rel))throw new OpException('少し待ってから作成し直してください');
        foreach([$base.'/releases',$base.'/shared',$rel] as $dir)if(!is_dir($dir))mkdir($dir,0755,true);
        Proc::must(['/bin/cp','-a','--',$review->directory($plan['token']).'/tree/.',$rel],null,600);
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rel,\FilesystemIterator::SKIP_DOTS)) as $entry){
            if($entry->isFile()&&preg_match('/\A\.env(?:\..*)?\z/D',$entry->getFilename()))unlink($entry->getPathname());
        }
        // 本番データから独立したコピーを作り、環境変数は試験用だけを使用する。
        (new Backups($this->cfg,$this->paths))->snapshotData($name,$base.'/shared',false);
        file_put_contents($base.'/shared/.env',$config['environment']);
        foreach(['tmp','sessions'] as $part)if(!is_dir($base.'/shared/'.$part))mkdir($base.'/shared/'.$part,0700,true);
        Proc::must(['/bin/chown','-Rh',$user.':'.$user,$base.'/shared',$rel],null,120);
        $stageSpec=array_replace($prod,['name'=>$id,'domain'=>$domain,'extra_conf'=>'','hooks'=>[],'shared'=>array_values(array_unique(array_merge(['.env'],$prod['shared']))),'auto_databases'=>true]);
        $stageCfg=array_replace($this->cfg,['www_root'=>$root,'web_user'=>$user,'web_group'=>$user]);
        $stageDeploy=new Deploy($stageCfg,$stagePaths,new Vhost($stageCfg,$stagePaths),new Health($stageCfg));
        $stageDeploy->linkShared($id,$stageSpec,$rel,new JobLog((int)($params['job_id']??0),$this->cfg['job_dir']));
        // Uploaded archives may contain owner-only permissions; Apache must be able to serve the code.
        Proc::must(['/usr/bin/find',$rel,'-type','d','-exec','/bin/chmod','755','{}','+'],null,120);
        Proc::must(['/usr/bin/find',$rel,'-type','f','-exec','/bin/chmod','644','{}','+'],null,120);
        chmod($base.'/shared',0755);$privateDatabases=array_flip(\Pjas\Agentd\SharedDatabases::discover($base.'/shared'));
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/shared',\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $entry){
            $relative=substr($entry->getPathname(),strlen($base.'/shared/') );if($entry->isLink()||preg_match('~^(?:tmp|sessions)(?:/|$)~',$relative))continue;
            if($entry->isDir()){chmod($entry->getPathname(),0755);continue;}
            $original=$this->paths->shared($name).'/'.$relative;
            if(!isset($privateDatabases[$relative])&&!preg_match('~(^|/)\.env(?:\..*)?$~',$relative)&&is_file($original)&&!is_link($original)&&(fileperms($original)&0004))chmod($entry->getPathname(),0644);
        }
        $docroot=rtrim($rel.'/'.$prod['docroot'],'/');if(!is_dir($docroot))throw new OpException('試験用の公開フォルダがありません。ビルド済みのファイルを用意して確認してください');
        $confPath=rtrim($this->cfg['apache_sites'],'/').'/pjas-'.$id.'.conf';
        if(is_file($confPath)&&!str_starts_with(file_get_contents($confPath),'# pjas-ctrl isolated preview'))throw new OpException('同じ名前の公開設定があるため試験環境を作成できません');
        $unit='pjas-'.$id.'.service';$runtime='/run/pjas-'.$id;$socket=$runtime.'/fpm.sock';
        $pool=$this->store->directory('previews').'/'.$id.'.conf';
        if($prod['runtime']!=='static'){
            $ini="[global]\ndaemonize = no\npid = {$runtime}/fpm.pid\nerror_log = {$runtime}/fpm-error.log\n[preview]\nuser = {$user}\ngroup = {$user}\nlisten = {$socket}\nlisten.owner = {$this->cfg['web_user']}\nlisten.group = {$this->cfg['web_group']}\nlisten.mode = 0660\npm = ondemand\npm.max_children = 3\npm.process_idle_timeout = 30s\nclear_env = yes\nsecurity.limit_extensions = .php\nphp_admin_value[open_basedir] = {$base}/releases:{$base}/shared\nphp_admin_value[disable_functions] = exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,putenv,dl,mail\nphp_admin_value[disable_classes] = FFI\nphp_admin_flag[allow_url_fopen] = off\nphp_admin_value[session.save_path] = {$base}/shared/sessions\nphp_admin_value[upload_tmp_dir] = {$base}/shared/tmp\nphp_admin_value[error_log] = {$base}/shared/php-error.log\n";
            file_put_contents($pool,$ini);chmod($pool,0600);
            $binary=$this->cfg['php_fpm_bin']??'/usr/sbin/php-fpm8.4';if(!is_executable($binary))throw new OpException('試験用PHP-FPMがありません。PHP 8.4 FPMをインストールしてください');
            Proc::must([$binary,'--test','--fpm-config',$pool],null,30);
            $definition="[Unit]\nDescription=pjas isolated preview {$id}\nAfter=network.target\n[Service]\nType=simple\nExecStart={$binary} --nodaemonize --fpm-config {$pool}\nRestart=on-failure\nRuntimeDirectory=pjas-{$id}\nPrivateNetwork=yes\nPrivateTmp=yes\nProtectSystem=strict\nProtectHome=yes\nNoNewPrivileges=yes\nInaccessiblePaths=-/run/mysqld -/run/postgresql -/var/lib/mysql -/var/lib/postgresql\nReadWritePaths={$base}/shared {$runtime}\n[Install]\nWantedBy=multi-user.target\n";
            file_put_contents(($this->cfg['daemon_unit_dir']??'/etc/systemd/system').'/'.$unit,$definition);Proc::must([$this->cfg['systemctl'],'daemon-reload'],null,30);Proc::must([$this->cfg['systemctl'],'restart',$unit],null,45);
        }
        $credentials=$this->password($name);
        $fallback=is_file($docroot.'/index.php')?'/index.php':(is_file($docroot.'/index.html')?'/index.html':'disabled');
        $body="    ServerName {$domain}\n    DocumentRoot {$docroot}\n    <Directory {$docroot}>\n        AllowOverride None\n        Options -Indexes +FollowSymLinks\n        AuthType Basic\n        AuthName \"試験公開\"\n        AuthUserFile {$base}/.htpasswd\n        Require valid-user\n        FallbackResource {$fallback}\n    </Directory>\n    Header always set X-Robots-Tag \"noindex, nofollow\"\n    Header always set Referrer-Policy \"no-referrer\"\n    Header always set Content-Security-Policy \"default-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'\"\n";
        $body.="    <FilesMatch \"^\\.\">\n        Require all denied\n    </FilesMatch>\n";
        $body.=$prod['runtime']==='static'?"    <FilesMatch \\.php$>\n        Require all denied\n    </FilesMatch>\n":"    <FilesMatch \\.php$>\n        SetHandler \"proxy:unix:{$socket}|fcgi://localhost\"\n    </FilesMatch>\n";
        $cert=$this->cfg['tls_cert_file'];$key=$this->cfg['tls_key_file'];$conf="# pjas-ctrl isolated preview\n<VirtualHost *:80>\n    ServerName {$domain}\n    Redirect permanent / https://{$domain}/\n</VirtualHost>\n<VirtualHost *:443>\n".$body."    SSLEngine on\n    SSLCertificateFile {$cert}\n    SSLCertificateKeyFile {$key}\n</VirtualHost>\n";
        $lock=fopen($this->cfg['apache_sites'].'/.pjas-vhost.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new OpException('公開設定をロックできません');$old=is_file($confPath)?file_get_contents($confPath):null;
        try{file_put_contents($confPath,$conf);chmod($confPath,0644);Proc::must([$this->cfg['a2ensite'],'-q','pjas-'.$id],null,30);(new Vhost($this->cfg,$this->paths))->reload();}
        catch(\Throwable $e){if($old===null){@unlink($confPath);Proc::run([$this->cfg['a2dissite'],'-q','pjas-'.$id],null,30);}else file_put_contents($confPath,$old);Proc::run([$this->cfg['apachectl'],'graceful'],null,60);throw $e;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
        $this->store->write('preview-state-'.$name,['url'=>'https://'.$domain,'created_at'=>gmdate('c'),'source'=>$plan['meta']['sha256']??'','unit'=>$unit]);
        return ['url'=>'https://'.$domain]+$credentials;
    }
    public function remove(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');$guard=$this->store->lock('preview-'.$name);
        try{
            $id=$this->name($name);$conf=rtrim($this->cfg['apache_sites'],'/').'/pjas-'.$id.'.conf';
            if(is_link($conf)||(is_file($conf)&&!str_starts_with(file_get_contents($conf),'# pjas-ctrl isolated preview')))throw new OpException('試験用ではない設定は削除できません');
            $lock=fopen($this->cfg['apache_sites'].'/.pjas-vhost.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new OpException('公開設定をロックできません');
            $old=is_file($conf)?file_get_contents($conf):null;
            try{Proc::must([$this->cfg['a2dissite'],'-q','pjas-'.$id],null,30);if($old!==null)unlink($conf);(new Vhost($this->cfg,$this->paths))->reload();}
            catch(\Throwable $e){if($old!==null){file_put_contents($conf,$old);Proc::run([$this->cfg['a2ensite'],'-q','pjas-'.$id],null,30);Proc::run([$this->cfg['apachectl'],'graceful'],null,60);}throw $e;}
            finally{flock($lock,LOCK_UN);fclose($lock);}
            Proc::must([$this->cfg['systemctl'],'stop','pjas-'.$id.'.service'],null,30);$this->store->write('preview-state-'.$name,[]);return ['removed'=>true];
        }finally{flock($guard,LOCK_UN);fclose($guard);}
    }
}
