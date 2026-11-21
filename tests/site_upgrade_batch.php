<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{SiteUpgradeBatch,ControlStore};
[$dir,$cfg]=fixture('batch-upgrade');
try{
    $store=new ControlStore($cfg);$store->write('components-pjas-cert',[]);
    $store->write('isolation-pjas-chat',['status'=>'ready','runtime'=>'php8.5-fpm']);
    $db=static fn($op,$name,$args)=>['spec'=>['runtime'=>match($name){'pjas-calc','pjas-drive'=>'static','pjas-chat'=>'php8.5-fpm',default=>'php8.4-fpm'}]];
    $plans=[];$states=[];$calls=[];$fail=false;
    $single=function($op,$args)use(&$plans,&$states,&$calls,&$fail){
        $calls[]=[$op,$args];
        if($op==='preflight')return [];
        if($op==='plan'){$id=bin2hex(random_bytes(12));$plans[$args[0]]=['id'=>$id,'target'=>$args[1]];$states[$id]='planned';return ['id'=>$id];}
        $id=$args[0];
        if($op==='status')return ['phase'=>$states[$id]];
        if($op==='apply'){if($fail&&$id===$plans['pjas-financial']['id']){$states[$id]='isolating';throw new RuntimeException('injected failure');}$states[$id]='prepared';return ['phase'=>'prepared'];}
        if($op==='activate'){$states[$id]='active';return [];}
        if($op==='rollback'){$states[$id]='rolled-back';return ['phase'=>'rolled-back'];}
        throw new RuntimeException($op);
    };
    $batch=new SiteUpgradeBatch($cfg,$db,$single);
    $j=$batch->plan(['pjas-calc','pjas-cert','pjas-chat','pjas-drive','pjas-financial'],'php8.5-fpm');
    check($plans['pjas-calc']['target']==='static'&&$plans['pjas-drive']['target']==='static','静的サイトはPHPサイトへ変更しない');
    check($j['sites']['pjas-cert']['phase']==='manual'&&!isset($plans['pjas-cert']),'v2 CAは初期化・移行せず明示的に分離');
    check($j['sites']['pjas-chat']['phase']==='current'&&!isset($plans['pjas-chat']),'PHPと隔離が完了済みのサイトを再移行しない');
    rejects(fn()=>$batch->apply($j['id'],true,true,true,false));
    check(!array_filter($calls,fn($c)=>$c[0]==='apply'),'manualの除外に同意するまでは一件も停止しない');
    $fail=true;rejects(fn()=>$batch->apply($j['id'],true,true,true,true));
    check($states[$plans['pjas-calc']['id']]==='active'&&$states[$plans['pjas-drive']['id']]==='active'&&$states[$plans['pjas-financial']['id']]==='isolating','一件ずつ再開し、失敗したサイトで処理を止める');
    $states[$plans['pjas-financial']['id']]='prepared';$fail=false;$done=$batch->apply($j['id'],true,true,true,true);
    check($done['sites']['pjas-financial']['phase']==='active','個別復旧後は同じ一括IDで残りを再開できる');
    $calcCalls=array_filter($calls,fn($c)=>$c[0]==='apply'&&$c[1][0]===$plans['pjas-calc']['id']);check(count($calcCalls)===1,'一括再実行で完了済みサイトを変更しない');
    $store->write('upgrade-active-pjas-memo',['id'=>str_repeat('a',24)]);$blocked=$batch->plan(['pjas-calc','pjas-memo'],'php8.5-fpm');
    rejects(fn()=>$batch->apply($blocked['id'],true,true,true,true));check($blocked['sites']['pjas-memo']['phase']==='blocked','進行中の個別移行を一括で上書きしない');
    rejects(fn()=>$batch->rollback($j['id'],true));
    $staged=$batch->plan(['pjas-calc','pjas-financial'],'php8.5-fpm');
    $fail=true;
    rejects(fn()=>$batch->prepare($staged['id'],true,true));
    check($batch->status($staged['id'])['sites']['pjas-calc']['phase']==='prepared','後続サイトが失敗しても先行サイトは公開しない');
    $calls=[];
    $restored=$batch->rollback($staged['id'],true);
    $order=array_values(array_map(fn($c)=>$c[1][0],array_filter($calls,fn($c)=>$c[0]==='rollback')));
    check($order===[$plans['pjas-financial']['id'],$plans['pjas-calc']['id']],'中断した一括移行を逆順で復旧する');
    check($restored['sites']['pjas-calc']['phase']==='rolled-back','復旧した状態を取得できる');
    $calls=[];
    $batch->rollback($staged['id'],true);
    check(!array_filter($calls,fn($c)=>$c[0]==='rollback'),'復旧済みサイトを再変更しない');
    rejects(fn()=>$batch->apply($staged['id'],true,true,true,true));
}finally{cleanup($dir);}
