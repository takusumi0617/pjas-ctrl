<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{Spec,SiteIsolation,SitePause,ControlStore,Paths,Proc,OpException};
use Pjas\Shared\PhpRuntime;

final class Isolation
{
    public function __construct(private array $cfg) {}
    /** A maintenance window is explicit; failures leave the site paused for recovery. */
    public function apply(array $params): array
    {
        $spec=Spec::service($params['service']??null);$name=$spec['name'];
        if(empty($params['confirm'])||empty($params['standardize']))throw new OpException('停止を伴う移行の確認が必要です');
        if(Components::protectedSite($this->cfg,$name))throw new OpException('v2サイトは隔離コンポーネント画面から更新してください');
        $runtime=(string)($params['runtime']??'');
        if(!in_array($runtime,PhpRuntime::RUNTIMES,true))throw new OpException('PHPバージョンを選んでください');
        PhpRuntime::requireInstalled($runtime);
        $iso=new SiteIsolation($this->cfg);$status=$iso->status($params);
        if($status['blockers'])throw new OpException(implode(' / ',$status['blockers']));
        $paths=new Paths($this->cfg['www_root']);
        if(!$paths->currentTag($name))throw new OpException('先に既存ファイルの取り込みと公開を完了してください');
        if(!(new SitePause($this->cfg))->read($name)['enabled'])throw new OpException('先にサイトを一時停止してください。移行後も停止を維持します');
        $oldState=$iso->state($name);$oldRuntime=$oldState['runtime']??$spec['runtime'];
        $backup=(new ControlStore($this->cfg))->directory('isolation-backups').'/'.$name.'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        mkdir($backup,0700);
        $base=$paths->base($name);
        $acl=Proc::run(['/usr/bin/getfacl','-R','-P','--absolute-names',$base],null,300,null,[],null,$backup.'/permissions.acl');
        if($acl['code']!==0)throw new OpException('権限を退避できません');
        $vhost=new Vhost($this->cfg,$paths);$conf=$vhost->confPath($name);$oldConf=(string)file_get_contents($conf);
        file_put_contents($backup.'/apache.conf',$oldConf);file_put_contents($backup.'/state.json',json_encode($oldState,JSON_THROW_ON_ERROR));
        // Terminate old PHP requests after Apache has switched to the independent pause page.
        if($oldRuntime!=='static')Proc::must([$this->cfg['systemctl'],'restart',$oldRuntime]);
        $oldCfg=$oldState?$iso->scope($name):$this->cfg;
        $safety=(new Backups($oldCfg,$paths))->createUnlocked($spec,'before-isolation');
        $targetPool=$runtime==='static'?null:'/etc/php/'.PhpRuntime::version($runtime).'/fpm/pool.d/pjas-'.$name.'.conf';
        $priorPool=$targetPool!==null&&is_file($targetPool)?file_get_contents($targetPool):null;
        $inherited=$this->cfg['apache_sites'].'/.pjas-inherited-'.$name.'.json';if(is_link($inherited))throw new OpException('継承設定がリンクです');$priorInherited=is_file($inherited)?file_get_contents($inherited):null;
        if($priorInherited!==null)file_put_contents($backup.'/inherited.json',$priorInherited);
        $sourcePool=$oldState&&$oldRuntime!=='static'?'/etc/php/'.PhpRuntime::version($oldRuntime).'/fpm/pool.d/pjas-'.$name.'.conf':null;
        $sourceBody=$sourcePool&&is_file($sourcePool)?file_get_contents($sourcePool):null;
        if($sourceBody!==null)file_put_contents($backup.'/old-pool.conf',$sourceBody);
        $newSpec=array_replace($spec,['runtime'=>$runtime,'extra_conf'=>'','allow_override'=>'None']);
        try {
            $scoped=$iso->provision($newSpec);
            SiteIsolation::protect($scoped,$name,$spec['docroot']);
            // Check with the selected CLI, never fall back to the system PHP.
            if($runtime!=='static') {
                $files=Proc::must(['/usr/bin/find','-P',$paths->current($name).'/','-type','f','-name','*.php']);
                foreach(array_filter(explode("\n",trim($files))) as $file)Proc::must([PhpRuntime::cli($runtime),'-n','-l',$file],null,20,$scoped['web_user']);
            }
            if($priorInherited!==null&&!unlink($inherited))throw new OpException('継承設定を退避できません');
            (new Vhost($scoped,$paths))->apply(['service'=>$newSpec]);
            if($oldState&&$oldRuntime!=='static'&&$oldRuntime!==$runtime){$oldPool='/etc/php/'.PhpRuntime::version($oldRuntime).'/fpm/pool.d/pjas-'.$name.'.conf';if(is_link($oldPool))throw new OpException('旧FPM設定がリンクです');if(is_file($oldPool))unlink($oldPool);Proc::must([PhpRuntime::fpm($oldRuntime),'--test']);Proc::must([$this->cfg['systemctl'],'restart',$oldRuntime]);}
            return ['safety_backup'=>$safety['id'],'isolated'=>true,'runtime'=>$runtime,'paused'=>true,'backup'=>$backup];
        } catch(\Throwable $e) {
            if($sourceBody!==null)file_put_contents($sourcePool,$sourceBody);
            if($priorInherited!==null)file_put_contents($inherited,$priorInherited);
            if($targetPool!==null){if($priorPool===null)@unlink($targetPool);else file_put_contents($targetPool,$priorPool);Proc::run([$this->cfg['systemctl'],'reload-or-restart',$runtime]);}
            $restore=Proc::run(['/usr/bin/setfacl','--restore='.$backup.'/permissions.acl'],null,300);
            if($oldState)(new ControlStore($this->cfg))->write('isolation-'.$name,$oldState);
            else @unlink((new ControlStore($this->cfg))->directory().'/isolation-'.$name.'.json');
            file_put_contents($conf,$oldConf);
            if($sourcePool!==null)Proc::run([$this->cfg['systemctl'],'reload-or-restart',$oldRuntime]);
            $reload=Proc::run([$this->cfg['apachectl'],'graceful'],null,60);
            throw new OpException('移行に失敗しました。一時停止を維持します。退避先: '.$backup.' / 権限復旧: '.($restore['code']===0?'成功':'要確認').' / Apache復旧: '.($reload['code']===0?'成功':'要確認').' / '.$e->getMessage());
        }
    }
}
