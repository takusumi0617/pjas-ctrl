<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once dirname(__DIR__,2).'/shared/PhpRuntime.php';
use Pjas\Shared\PhpRuntime;

/** Site identities are agent-owned state, never identities supplied by an RPC caller. */
final class SiteIsolation
{
    public function __construct(private array $cfg) {}
    public static function identity(string $name): string { return 'pj-'.substr(hash('sha256',Spec::name($name)),0,24); }
    public function state(string $name): array { return (new ControlStore($this->cfg))->read('isolation-'.Spec::name($name)); }
    public function scope(string $name,bool $preparing=false): array
    {
        $state=$this->state($name);
        if (!$state) return $this->cfg;
        if(!$preparing&&($state['status']??'')!=='ready')throw new OpException('隔離の準備が未完了です。登録または移行を再実行してください');
        $apache=isset($this->cfg['apache_user'])?posix_getpwnam($this->cfg['apache_user']):false;$legacy=posix_getpwnam($this->cfg['web_user']);
        if(!$apache||$apache['uid']===0||($legacy&&$apache['uid']===$legacy['uid']))throw new OpException('ApacheとPHPの実行ユーザーを分離してください');
        $user=self::identity($name); $pw=posix_getpwnam($user);$gr=posix_getgrnam($user);
        if (!$pw||!$gr||$pw['uid']===0||$pw['gid']!==$gr['gid']||$gr['members']) throw new OpException('サイト専用ユーザーの権限を確認してください');
        foreach (posix_getgrgid($pw['gid'])['members'] as $member) if ($member!==$user) throw new OpException('サイトのグループが共有されています');
        $groups=Proc::must(['/usr/bin/id','-G',$user]);
        if (array_values(array_unique(preg_split('/\s+/',trim($groups))))!==[(string)$gr['gid']]) throw new OpException('サイト専用ユーザーに補助グループを付けないでください');
        return array_replace($this->cfg,['web_user'=>$user,'web_group'=>$user,'isolation_site'=>$name,'isolation_runtime'=>$state['runtime'],'isolation_api_front_controller'=>$state['api_front_controller']??null,'apache_user'=>$this->cfg['apache_user']]);
    }
    public function provision(array $spec): array
    {
        $name=Spec::name($spec['name']);$runtime=$spec['runtime'];PhpRuntime::requireInstalled($runtime);
        $root=$this->cfg['www_root'];$st=lstat($root);
        if(is_link($root)||!$st||$st['uid']!==0||($st['mode']&0022))throw new OpException('サイトの親フォルダはroot所有・他ユーザー書き込み禁止にしてください');
        if (posix_geteuid()!==0) throw new OpException('サイト隔離の準備にはrootが必要です');
        foreach (['/usr/bin/setfacl','/usr/bin/getfacl'] as $bin) if(!is_executable($bin))throw new OpException('aclパッケージをインストールしてください');
        $apache=$this->cfg['apache_user']??null;
        if(!$apache||!posix_getpwnam($apache)||posix_getpwnam($apache)['uid']===posix_getpwnam($this->cfg['web_user'])['uid'])throw new OpException('初回準備でApacheとPHPの実行ユーザーを分離してください');
        $base=$this->cfg['www_root'].'/'.$name;
        if(!is_dir($base.'/shared')){if(is_link($base)||is_link($base.'/shared'))throw new OpException('サイト領域がリンクです');if(!is_dir($base)&&!mkdir($base,0750))throw new OpException('サイト領域を作成できません');if(!mkdir($base.'/shared',0700))throw new OpException('共有領域を作成できません');}
        $user=self::identity($name);$existing=$this->state($name);
        $allocated=(new ControlStore($this->cfg))->read('identity-'.$name);
        if (!$existing && !$allocated && (posix_getpwnam($user)||posix_getgrnam($user))) throw new OpException('専用ユーザー名が既存アカウントと衝突しています');
        if (!$existing) {
            if(!$allocated) {
                Proc::must([$this->cfg['systemctl'],'start','pjas-site-identity@'.$name.'.service']);
                $allocated=(new ControlStore($this->cfg))->read('identity-'.$name);
                if(($allocated['user']??'')!==$user)throw new OpException('専用アカウントの作成を確認できません');
            }
            (new ControlStore($this->cfg))->write('isolation-'.$name,['runtime'=>$runtime,'status'=>'preparing']);
        }
        $scoped=$this->scope($name,true);
        if ($runtime!=='static') {
            $version=PhpRuntime::version($runtime);$pool='/etc/php/'.$version.'/fpm/pool.d/pjas-'.$name.'.conf';
            if(is_link($pool))throw new OpException('FPM設定先がリンクです');
            foreach(['.tmp','.sessions'] as $part){$dir=$base.'/shared/'.$part;if(is_link($dir))throw new OpException('PHP一時領域がリンクです');if(!is_dir($dir)&&!mkdir($dir,0700))throw new OpException('PHP一時領域を作成できません');chown($dir,$user);chgrp($dir,$user);chmod($dir,0700);}
            $environment=self::poolEnvironment($name);
            $body=self::pool($name,$runtime,$scoped['apache_user'],$base,$environment);
            $old=is_file($pool)?file_get_contents($pool):null;
            if ($old===false) throw new OpException('現在のFPM設定を読み取れません');
            if ($name==='pjas-chat' && !$environment && $old!==null && preg_match('/^\h*env\[/m',$old)) {
                throw new OpException('pjas-chatの既存FPM環境変数を管理者設定へ取り込んでください');
            }
            try {
                if(file_put_contents($pool,$body,LOCK_EX)===false)throw new OpException('FPM設定を保存できません');chmod($pool,0644);
                Proc::must([PhpRuntime::fpm($runtime),'--test']);
                Proc::must([$this->cfg['systemctl'],'reload-or-restart',$runtime]);
            } catch (\Throwable $e) { if($old===null)@unlink($pool);else file_put_contents($pool,$old);throw $e; }
        }
        (new ControlStore($this->cfg))->write('isolation-'.$name,array_replace($existing,['runtime'=>$runtime,'status'=>'ready']));
        return $this->scope($name);
    }
    /** Recognize a Laravel release without executing application code or trusting .htaccess. */
    public static function frontController(array $cfg,array $spec): bool
    {
        if($spec['runtime']==='static'||$spec['docroot']==='')return false;
        $paths=new Paths($cfg['www_root']);$release=realpath($paths->current($spec['name']));$base=realpath($paths->base($spec['name']));
        if(!$release||!$base||!str_starts_with($release.'/',$base.'/'))return false;
        foreach(['artisan','bootstrap/app.php',trim($spec['docroot'],'/').'/index.php'] as $relative){
            $file=$release.'/'.$relative;$real=realpath($file);
            if(is_link($file)||!is_file($file)||!$real||!str_starts_with($real,$release.'/'))return false;
        }
        return true;
    }
    public static function apiScript(array $cfg,array $spec,string $relative): string
    {
        $relative=Spec::relPath($relative,'API front controller');
        if($spec['runtime']==='static'||!preg_match('~\A[A-Za-z0-9_./-]+\.php\z~D',$relative))throw new OpException('APIのPHPファイルをリリース内の相対パスで指定してください');
        $paths=new Paths($cfg['www_root']);$release=realpath($paths->current($spec['name']));$file=$paths->current($spec['name']).'/'.$relative;$real=realpath($file);
        if(!$release||!$real||!is_file($file)||is_link($file)||!str_starts_with($real,$release.'/')||!str_starts_with($release.'/',realpath($paths->base($spec['name'])).'/'))throw new OpException('APIファイルがリリース内にありません');
        return $file;
    }
    public static function socket(string $name,string $runtime): string { return '/run/php/pjas-'.Spec::name($name).'-'.PhpRuntime::version($runtime).'.sock'; }
    public static function poolEnvironment(string $name): array
    {
        if ($name !== 'pjas-chat') return [];
        $file = '/etc/pjas/site-pool-env/pjas-chat.json';
        if (!file_exists($file) && !is_link($file)) return [];
        foreach (['/etc/pjas', dirname($file), $file] as $path) {
            $info = lstat($path);
            if (!$info || is_link($path) || $info['uid'] !== 0 || ($info['mode'] & 0022)
                || ($path === $file ? (!is_file($path) || ($info['mode'] & 0077)) : !is_dir($path))) {
                throw new OpException('FPM環境変数ファイルの所有者・権限が不正です');
            }
        }
        $content = file_get_contents($file);
        if ($content === false) throw new OpException('FPM環境変数ファイルを読み取れません');
        $environment = json_decode($content,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($environment) || !$environment) throw new OpException('FPM環境変数ファイルが空または不正です');
        return $environment;
    }

    private static function environmentLines(string $name, array $environment): string
    {
        if (!$environment) return '';
        $fixed = ['CF_ACCESS_TEAM'=>'$CF_ACCESS_TEAM','CF_ACCESS_AUD'=>'$CF_ACCESS_AUD','PJAS_DB'=>'/var/lib/pjas-chat/pjas.sqlite'];
        $key = $environment['VAPID_PUBLIC_KEY'] ?? null;
        if ($name !== 'pjas-chat' || count($environment) !== 4 || !is_string($key)
            || !preg_match('/\A[A-Za-z0-9_-]{87}\z/D', $key)) {
            throw new OpException('pjas-chatのFPM環境変数が不正です');
        }
        foreach ($fixed as $name => $value) {
            if (($environment[$name] ?? null) !== $value) throw new OpException('許可されていないFPM環境変数の値です');
        }
        return "\nenv[CF_ACCESS_TEAM] = \$CF_ACCESS_TEAM\nenv[CF_ACCESS_AUD] = \$CF_ACCESS_AUD\n"
            ."env[PJAS_DB] = \"/var/lib/pjas-chat/pjas.sqlite\"\nenv[VAPID_PUBLIC_KEY] = \"".$key."\"\n";
    }

    public static function pool(string $name,string $runtime,string $apache,string $base='/var/www',array $environment=[]): string
    {
        $user=self::identity($name);
        if(!preg_match('/\A[a-z_][a-z0-9_-]*\z/D',$apache))throw new OpException('Apacheユーザーが不正です');
        return "[pjas-{$name}]\nuser = {$user}\ngroup = {$user}\nlisten = ".self::socket($name,$runtime)."\nlisten.owner = {$apache}\nlisten.group = {$apache}\nlisten.mode = 0600\npm = ondemand\npm.max_children = 5\npm.process_idle_timeout = 10s\nclear_env = yes\nsecurity.limit_extensions = .php\nphp_admin_flag[display_errors] = off\nphp_admin_value[sys_temp_dir] = {$base}/shared/.tmp\nphp_admin_value[upload_tmp_dir] = {$base}/shared/.tmp\nphp_admin_value[session.save_path] = {$base}/shared/.sessions\n".self::environmentLines($name,$environment);
    }
    public static function protect(array $cfg,string $name,string $docroot): void
    {
        if (($cfg['isolation_site']??null)!==$name) return;
        $paths=new Paths($cfg['www_root']);$user=self::identity($name);
        $pw=posix_getpwnam($user);$group=posix_getgrnam($user);$apache=posix_getpwnam($cfg['apache_user']);
        if(!$pw||!$group||!$apache)throw new OpException('隔離アカウントがありません');
        Proc::must(['/usr/bin/python3',__DIR__.'/isolate_tree.py',$paths->base($name),(string)$pw['uid'],(string)$group['gid'],(string)$apache['uid'],Spec::relPath($docroot,'DocumentRoot')],null,600);
    }
    public function status(array $params): array
    {
        $spec=Spec::service($params['service']??null);$name=$spec['name'];$state=$this->state($name);
        $blockers=[];$warnings=[];
        if (is_file($this->cfg['apache_sites'].'/.pjas-inherited-'.$name.'.json'))$warnings[]='継承Apache設定を退避して標準設定に切り替えます。独自のAPI・プロキシ・別名ドメインは引き継ぎません';
        // The daemon registry is stored separately; inspect its actual rows too.
        foreach ((new \Pjas\Agentd\Ops\Daemon($this->cfg))->listing(['service'=>$name])['daemons']??[] as $row)$blockers[]='常駐処理を含む移行はCLIのpjas-site-upgradeを使用してください（関連付けは維持します）';
        foreach(glob(($this->cfg['cron_script_dir']??'/var/lib/pjas/cron').'/task-*.sh')?:[] as $file) if(str_contains((string)file_get_contents($file),$this->cfg['www_root'].'/'.$name.'/'))$blockers[]='cronを含む移行はCLIのpjas-site-upgradeを使用できます';
        if(trim($spec['extra_conf'])!=='')$warnings[]='追加Apache設定を退避して削除します。アプリの公開ルートを確認してください';
        try {
            $drift = SiteDrift::inspect(($state['status']??'')==='ready' ? $this->scope($name) : $this->cfg, $spec, $state);
        } catch (\Throwable $e) {
            $drift = [['path'=>$name,'status'=>'unreadable','detail'=>'照合に必要な実行環境を確認できません']];
        }
        return ['drift'=>$drift,'isolated'=>($state['status']??'')==='ready','state'=>$state,'user'=>$state?self::identity($name):$this->cfg['web_user'],'runtime'=>$state['runtime']??$spec['runtime'],'warnings'=>$warnings,'blockers'=>array_values(array_unique($blockers))];
    }
}
