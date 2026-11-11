<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{Proc,ControlStore};
use Pjas\Agentd\Ops\Certificates;
[$dir,$cfg]=fixture('tls-apply');$server=null;
try {
 Proc::must(['/usr/bin/openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',$dir.'/key','-out',$dir.'/cert','-days','2','-subj','/CN=cert.example.com','-addext','subjectAltName=DNS:cert.example.com','-addext','extendedKeyUsage=serverAuth']);chmod($dir.'/key',0600);
 $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);fclose($listener);
 $server=proc_open(['/usr/bin/openssl','s_server','-quiet','-accept','127.0.0.1:'.$port,'-cert',$dir.'/cert','-key',$dir.'/key'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
 $cfg['component_catalog']=$dir.'/catalog';$cfg['tls_cert_file']=$dir.'/cert';$cfg['tls_key_file']=$dir.'/key';
 $provider=['sites'=>['cert'],'type'=>'manual','certificate'=>$dir.'/cert','key'=>$dir.'/key','trust'=>$dir.'/cert','probe_port'=>$port];
 file_put_contents($cfg['component_catalog'],json_encode(['version'=>1,'sites'=>['cert'=>['domain'=>'cert.example.com','runtime'=>'static','document_root'=>'public']],'tls_providers'=>['manual'=>$provider]]));chmod($cfg['component_catalog'],0600);
 mkdir($dir.'/www/cert',0755);mkdir($dir.'/www/cert/current',0755);mkdir($dir.'/www/cert/current/public',0755);
 // Port readiness only synchronizes the fixture; apply() verifies the actual SNI/cert.
 for($i=0;$i<50;$i++){ $fp=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);if($fp){fclose($fp);break;}usleep(20000); }
 $ops=new Certificates($cfg);$p=['service'=>'cert','provider'=>'manual'];$plan=$ops->plan($p);$r=$ops->apply($p+['review'=>$plan['review']]);
 check($r['verified'],'実際のTLSサーバーへSNI接続し配信証明書を照合する');
 $store=new ControlStore($cfg);$prior=$store->read('tls-cert');$priorConf=file_get_contents($dir.'/sites/pjas-cert.conf');$priorLink=readlink($cfg['control_dir'].'/tls/cert/current');
 // Fail precisely the next Apache reload; restoration reload succeeds.
 file_put_contents($cfg['apachectl'],"#!/bin/sh\nif [ \"\$1\" = graceful ] && [ -f '$dir/fail-once' ]; then rm '$dir/fail-once'; exit 1; fi\nexit 0\n");chmod($cfg['apachectl'],0700);touch($dir.'/fail-once');
 $plan=$ops->plan($p);rejects(fn()=>$ops->apply($p+['review'=>$plan['review']]));
 check($store->read('tls-cert')===$prior && readlink($cfg['control_dir'].'/tls/cert/current')===$priorLink && file_get_contents($dir.'/sites/pjas-cert.conf')===$priorConf,'reload失敗時に証明書参照とApache設定を元に戻す');
 $audit=$store->read('tls-audit-cert');check(end($audit)['result']==='failed-restored','復旧の成否を監査に残す');
} finally {if(is_resource($server)){proc_terminate($server);proc_close($server);}cleanup($dir);}
