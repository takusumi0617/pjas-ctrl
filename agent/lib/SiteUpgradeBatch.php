<?php
declare(strict_types=1);
namespace Pjas\Agentd;
use Pjas\Agentd\Ops\Components;
use Pjas\Shared\PhpRuntime;

/** A batch is a durable index of independent site transactions, never a bulk DB update. */
final class SiteUpgradeBatch
{
    private ControlStore $store;
    public function __construct(private array $cfg,private \Closure $database,private \Closure $single){$this->store=new ControlStore($cfg);}
    private function load(string $id):array {if(!preg_match('/\A[a-f0-9]{24}\z/D',$id))throw new OpException('一括移行IDが不正です');$j=$this->store->read('upgrade-batch-'.$id);if(!$j)throw new OpException('一括移行IDがありません');return $j;}
    private function save(array $j):void {$this->store->write('upgrade-batch-'.$j['id'],$j);}
    public function plan(array $names,string $runtime):array
    {
        if(!in_array($runtime,PhpRuntime::RUNTIMES,true)||$runtime==='static')throw new OpException('移行先PHPを指定してください。静的サイトは自動的にstaticを維持します');
        if(!$names||count($names)>100)throw new OpException('対象サイトは1〜100件で指定してください');
        $names=array_values(array_unique(array_map(Spec::name(...),$names)));$j=['id'=>bin2hex(random_bytes(12)),'sites'=>[]];
        foreach($names as $name){
            try{
                $bundle=($this->database)('read',$name,[]);$target=$bundle['spec']['runtime']==='static'?'static':$runtime;
                if(Components::protectedSite($this->cfg,$name)){$j['sites'][$name]=['phase'=>'manual','reason'=>'v2コンポーネントは専用の移行が必要です'];continue;}
                $pending=$this->store->read('upgrade-active-'.$name);
                if($pending){$j['sites'][$name]=['phase'=>'blocked','id'=>$pending['id'],'reason'=>'単独の移行が進行中です。先に完了またはrollbackしてください'];continue;}
                $state=(new SiteIsolation($this->cfg))->state($name);
                if(($state['status']??'')==='ready'&&($state['runtime']??'')===$target&&$bundle['spec']['runtime']===$target){$j['sites'][$name]=['phase'=>'current','target'=>$target];continue;}
                ($this->single)('preflight',[$target,$name]);
                $plan=($this->single)('plan',[$name,$target]);$j['sites'][$name]=['phase'=>'planned','id'=>$plan['id'],'target'=>$target];
            }catch(\Throwable $e){$j['sites'][$name]=['phase'=>'blocked','reason'=>$e->getMessage()];}
        }
        $this->save($j);return $j;
    }
    public function status(string $id):array
    {
        $j=$this->load($id);foreach($j['sites'] as &$site)if(isset($site['id'])&&$site['phase']!=='blocked')$site['phase']=($this->single)('status',[$site['id']])['phase'];unset($site);return $j;
    }
    public function apply(string $id,bool $standard,bool $external,bool $activate,bool $skipManual):array
    {
        if(!$standard||!$external||!$activate)throw new OpException('Apache標準化・外部処理停止・再開の確認フラグが必要です');
        $lock=$this->store->lock('upgrade-batch-'.$id);
        try{
            $j=$this->load($id);
            // Refuse known blockers before stopping the first site.
            foreach($j['sites'] as $name=>$site)if($site['phase']==='blocked'||($site['phase']==='manual'&&!$skipManual))throw new OpException($name.': '.$site['reason']);
            foreach($j['sites'] as $name=>&$site){
                if(in_array($site['phase'],['manual','current','active'],true))continue;
                try{
                    $status=($this->single)('status',[$site['id']]);
                    if($status['phase']!=='active'){if(!in_array($status['phase'],['prepared','activating'],true))($this->single)('apply',[$site['id'],true,true]);($this->single)('activate',[$site['id'],true]);}
                    $site['phase']='active';unset($site['error']);$this->save($j);
                }catch(\Throwable $e){$site['phase']=($this->single)('status',[$site['id']])['phase'];$site['error']='このサイトで停止しました。単独のstatusで確認してください';$this->save($j);throw new OpException($name.' / 単独移行ID '.$site['id'].' / '.$e->getMessage());}
            }unset($site);
            return $j;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
