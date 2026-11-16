<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{ControlStore,Spec,SiteIsolation,SitePause,SiteUpgrade,OpException};

/** Read-only, whitelisted projection of root state. Never expose private journals or unit bodies. */
final class SiteOverview
{
    public function __construct(private array $cfg) {}
    public function listing(array $p): array
    {
        $names=$p['sites']??[];
        if(!is_array($names)||count($names)>200)throw new OpException('対象サイトを200件以内で指定してください');
        $names=array_values(array_unique(array_map(Spec::name(...),$names)));
        $store=new ControlStore($this->cfg);$latest=[];$latestOrder=[];
        foreach(glob($store->root().'/site-upgrade-*.json')?:[] as $path){
            if(!preg_match('/site-upgrade-([a-f0-9]{24})\.json$/D',basename($path),$m))continue;
            $j=$store->read('site-upgrade-'.$m[1]);$name=$j['name']??'';
            if(!in_array($name,$names,true))continue;
            $order=[filemtime($path)?:0,$j['created_at']??'',($j['phase']??'')==='active'?2:(($j['phase']??'')==='rolled-back'?1:0)];
            if(!isset($latestOrder[$name])||$order>$latestOrder[$name]){$latest[$name]=$j;$latestOrder[$name]=$order;}
        }
        $result=[];
        foreach($names as $name){
            $iso=(new SiteIsolation($this->cfg))->state($name);
            $components=$store->read('components-'.$name);
            $pending=$store->read('upgrade-active-'.$name);
            $j=!empty($pending['id'])?$store->read('site-upgrade-'.$pending['id']):($latest[$name]??[]);
            if($j && ($j['name']??null)!==$name)throw new OpException('移行記録のサイトが一致しません');
            $upgrade=null;
            if($j){$upgrade=(new SiteUpgrade($this->cfg,static fn()=>throw new OpException('読み取り専用です')))->status($j['id']);unset($upgrade['journal']);if(!empty($upgrade['last_error']))$upgrade['last_error']='移行操作が完了していません。直近のHTTP結果とサーバーのjournalを確認してください';}
            $pause=array_intersect_key((new SitePause($this->cfg))->read($name),array_flip(['enabled','code','page_mode']));
            $result[$name]=['pause'=>$pause,'isolation'=>['isolated'=>($iso['status']??'')==='ready','user'=>$iso?SiteIsolation::identity($name):($this->cfg['web_user']??'www-data'),'runtime'=>$iso['runtime']??null,'routing'=>$iso['api_front_controller']??null],
                'component_site'=>(bool)$components,'upgrade'=>$upgrade];
        }
        return ['sites'=>$result,'observed_at'=>gmdate('c')];
    }
}
