<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\Ops\{ServerMonitor,Operations};use Pjas\Agentd\{Notifier,Paths,ControlStore};
[$dir,$cfg]=fixture('server-monitor');
try{
 check(ServerMonitor::memory("MemTotal: 1000 kB\nMemAvailable: 250 kB\n")===75.0&&ServerMonitor::memory('')===null,'メモリの空きから使用率を求め、欠測を正常扱いしない');
 check(ServerMonitor::cpu([20,0,10,70,0,0,0,0],[40,0,20,140,0,0,0,0])===30.0&&ServerMonitor::cpu([],[])===null,'CPU使用率は前回測定との差から求める');
 $url='https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_123456';$ops=new Operations($cfg,new Paths($cfg['www_root']));
 $s=$ops->saveSettings(['channel'=>'discord','webhook_url'=>$url]);check($s['notifications']['channel']==='discord'&&!isset($s['notifications']['webhook_url']),'Discordの秘密URLを設定後に返さない');
 $ops->saveSettings(['channel'=>'discord']);check((new ControlStore($cfg))->read('settings')['notifications']['webhook_url']===$url,'空欄で保存したDiscord URLを維持する');
 foreach(['http://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz','https://discord.com.evil.test/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz','https://discord.com@evil.test/path',$url.'?redirect=1',"$url\n"] as $bad)check(!Notifier::validateDiscord($bad),'偽のDiscord URLや追加パラメータを拒否する');
 rejects(fn()=>$ops->saveSettings(['channel'=>'discord','webhook_url'=>'https://localhost/secret']));
 $body=Notifier::payload('discord',str_repeat('😀',2000).'@everyone');check(mb_strlen($body['content'],'UTF-8')===950&&$body['allowed_mentions']['parse']===[],'Discordの文字数上限と全体メンションを制限する');
 check(Notifier::payload('webhook','hello')['text']==='hello','従来のWebhook形式を維持する');
 $monitor=new ServerMonitor($cfg);foreach(['ssh.service','pjas-agentd.service','apache2.service;id','../../evil','php8.5-fpm.service --all'] as $unit)rejects(fn()=>$monitor->restart(['unit'=>$unit]));
 $store=new ControlStore($cfg);$state=['time'=>time(),'checks'=>[],'cpu_ticks'=>[1,2],'fingerprints'=>['/secret'=>'hash'],'streak'=>['cpu'=>2],'history'=>[]];$store->write('server-monitor',$state);$out=$monitor->snapshot();check(!isset($out['fingerprints'],$out['cpu_ticks'],$out['streak'])&&$out['time']===$state['time'],'短時間の測定を再利用し内部データを公開しない');
 $state['time']=time()-120;$state['fingerprints']=['/etc/removed-fixture.conf'=>'abc'];$store->write('server-monitor',$state);
 $monitor=new ServerMonitor($cfg,static function($args){$out=match($args[0]){'/bin/systemctl'=>"Id=apache2.service\nLoadState=loaded\nActiveState=failed\nUnitFileState=enabled\n",'/usr/bin/journalctl'=>str_repeat("Failed password for fixture\n",21),'/usr/bin/apt'=>"demo/noble-security 2 amd64 [upgradable from: 1]\n",default=>''};return ['code'=>0,'out'=>$out,'err'=>''];});
 $out=$monitor->snapshot();$checks=array_column($out['checks'],null,'key');
 check(!$checks['service.apache2.service']['ok']&&!$checks['ssh']['ok']&&!$checks['updates']['ok'],'停止した主要サービス・SSH失敗急増・セキュリティ更新を検知する');
 check(count(array_filter($out['changes'],static fn($c)=>$c['file']==='/etc/removed-fixture.conf'&&$c['type']==='削除'))===1&&!isset($out['fingerprints']),'設定削除を検知し秘密の本文を保存しない');
 $calls=[];$restart=new ServerMonitor($cfg,static function($args)use(&$calls){$calls[]=$args;return ['code'=>0,'out'=>'active','err'=>''];});
 $restart->restart(['unit'=>'php'.\Pjas\Shared\PhpRuntime::controlVersion().'-fpm.service']);
 check(count(array_filter($calls,static fn($args)=>in_array('start',$args,true)&&in_array('pjas-control-web.service',$args,true)))===1,'管理用PHP再起動時は稼働していたHTTPを起動して確認する');
 $calls=[];$restart=new ServerMonitor($cfg,static function($args)use(&$calls){$calls[]=$args;return ['code'=>in_array('is-active',$args,true)?3:0,'out'=>'','err'=>''];});
 $restart->restart(['unit'=>'php'.\Pjas\Shared\PhpRuntime::controlVersion().'-fpm.service']);
 check(!array_filter($calls,static fn($args)=>in_array('start',$args,true)),'元々停止中の管理用HTTPを勝手に公開しない');
}finally{cleanup($dir);}
