<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\{Paths,Spec,ApacheHistory,Notifier};use Pjas\Agentd\Ops\{Operations,Vhost,Service};
[$dir,$cfg]=fixture('operations');$paths=new Paths($cfg['www_root']);$spec=Spec::service(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'static']);
try{
 (new Service($cfg,$paths))->create(['service'=>$spec]);$ops=new Operations($cfg,$paths);
 $s=$ops->saveSettings(['public_url'=>'https://ctrl.example.com','preview_domain'=>'example.com','channel'=>'webhook','webhook_url'=>'https://notify.example.com/secret']);check($s['notifications']['webhook_configured']&&!isset($s['notifications']['webhook_url']),'通知先の秘密URLを画面に再表示しない');
 $ops->saveSettings(['public_url'=>'https://ctrl.example.com','channel'=>'none']);$ops->saveSettings(['public_url'=>'https://ctrl.example.com','channel'=>'webhook']);
 $n=new Notifier($cfg,static function($s,$message){check($s['webhook_url']==='https://notify.example.com/secret'&&$message==='test','通知の無効化・再有効化で秘密URLを維持する');return ['sent'=>true];});$n->send('test');
 rejects(fn()=>$ops->saveSettings(['channel'=>'webhook','webhook_url'=>"https://notify.example.com/\nother"]));
 check(Operations::matchesDomain('*.example.com','demo.example.com')&&!Operations::matchesDomain('*.example.com','sub.demo.example.com')&&!Operations::matchesDomain('*.example.com','example.com'),'ワイルドカード証明書は一段だけのサブドメインに対応する');
 file_put_contents($cfg['apache_log_dir'].'/demo-error.log',"first\npassword=secret\nlast\n");$log=$ops->logs(['service'=>$spec,'source'=>'apache-error','query'=>'last']);check(trim($log['content'])==='last','対象サイトのログを絞り込んで表示する');rejects(fn()=>$ops->logs(['service'=>$spec,'source'=>'app','file'=>'../../other.log']));rejects(fn()=>$ops->logs(['service'=>$spec,'source'=>'daemon','unit'=>'ssh.service']));
 $sizes=$ops->resources(['service'=>$spec,'details'=>true]);check($sizes['total']>=$sizes['free']&&isset($sizes['shared']['bytes']),'空き容量とサイトごとの使用量を返す');
 $v=new Vhost($cfg,$paths);$h=new ApacheHistory($cfg);$old="<VirtualHost *:80>\nServerName demo.example.com\nDocumentRoot {$paths->base('demo')}/current/public\n# original\n</VirtualHost>\n";$rev=$h->save('demo',$old,'before','test');$new=str_replace('# original','# changed',$old);file_put_contents($v->confPath('demo'),$new);$preview=$v->restore(['service'=>$spec,'revision'=>$rev]);
 check($preview['git_diff']!==[]&&$preview['current_hash']===hash('sha256',$new),'Apache履歴を選んで復元前に差分を確認する');rejects(fn()=>$v->restore(['service'=>$spec,'revision'=>$rev,'action'=>'restore','current_hash'=>str_repeat('0',64)]));
 file_put_contents($cfg['apachectl'],"#!/bin/sh\n[ \"\$1\" != configtest ]\n");rejects(fn()=>$v->restore(['service'=>$spec,'revision'=>$rev,'action'=>'restore','current_hash'=>$preview['current_hash']]));check(file_get_contents($v->confPath('demo'))===$new,'設定検査に失敗したら復元前のApache設定へ戻す');
 file_put_contents($cfg['apachectl'],"#!/bin/sh\nexit 0\n");$v->restore(['service'=>$spec,'revision'=>$rev,'action'=>'restore','current_hash'=>$preview['current_hash']]);check(file_get_contents($v->confPath('demo'))===$old,'検査が通った履歴を復元し、次の継承設定にも反映する');
}finally{cleanup($dir);}
