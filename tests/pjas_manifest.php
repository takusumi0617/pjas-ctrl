<?php
declare(strict_types=1);
foreach (['OpException','Spec','Paths','Proc','JobLog','InheritedVhost','DaemonDefinition','GitRepositories','ManifestImport'] as $f) require_once dirname(__DIR__).'/agent/lib/'.$f.'.php';
foreach (['Vhost','Health','Deploy','Daemon','ScheduledTask'] as $f) require_once dirname(__DIR__).'/agent/ops/'.$f.'.php';
use Pjas\Domain\PjasManifest;
use Pjas\Agentd\ManifestImport;
use Pjas\Agentd\GitRepositories;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Ops\Health;
use Pjas\Agentd\Ops\Deploy;
function check(bool $v,string $m): void { if (!$v) throw new RuntimeException($m); echo 'ok '.$m."\n"; }
function rejects(callable $f): void { try { $f(); } catch (InvalidArgumentException|\Pjas\Agentd\OpException) { return; } throw new RuntimeException('拒否されませんでした'); }
$manifest=['version'=>1,'site'=>['name'=>'old-project','document_root'=>'dist','runtime'=>'static'],
 'apache'=>['file'=>'deploy/site.conf'],'shared'=>['database'], 'prepare'=>[],
 'daemons'=>[['name'=>'worker','command'=>'exec sleep 120','start_on_boot'=>true]],
 'cron'=>[['name'=>'clean','schedule'=>'*/5 * * * *','command'=>'/usr/bin/php /var/www/old-project/cron/expire.php']]];
$m=PjasManifest::parse(json_encode($manifest));
check(PjasManifest::defaults($m)['docroot']==='dist','サイト設定・共有パス・準備処理を読み込む');
rejects(fn()=>PjasManifest::parse('{bad'));
rejects(fn()=>PjasManifest::parse(json_encode(array_replace($manifest,['version'=>2]))));
rejects(fn()=>PjasManifest::parse(json_encode($manifest+['typo'=>true])));
rejects(fn()=>PjasManifest::parse(json_encode(array_replace($manifest,['apache'=>['file'=>'../outside']]))));
$spec=['name'=>'new-project','domain'=>'new.example.com','runtime'=>'static','docroot'=>'dist','tls'=>1,'allow_override'=>'None','extra_conf'=>'','shared'=>[],'hooks'=>[]];
$conf="<VirtualHost *:443>\nServerName old.example.com\nDocumentRoot /var/www/old-project/dist\n<Directory /var/www/old-project/dist>\nRequire all granted\nUnknownDirective preserve\n</Directory>\nProxyPassMatch ^/api unix:/run/php/php8.4-fpm.sock|fcgi://localhost/var/www/old-project/api/index.php\nSSLCertificateFile /old/ssl.pem\n</VirtualHost>\n";
$dir=sys_get_temp_dir().'/pjas-manifest-'.bin2hex(random_bytes(6)); mkdir($dir); mkdir($dir.'/sites'); mkdir($dir.'/enabled'); mkdir($dir.'/www/new-project/current/dist',0755,true);
$cfg=['www_root'=>$dir.'/www','github_repo_dir'=>$dir.'/repos','apache_sites'=>$dir.'/sites','apache_sites_enabled'=>$dir.'/enabled',
 'daemon_dir'=>$dir.'/daemons','daemon_unit_dir'=>$dir.'/units','cron_dir'=>$dir.'/cron','cron_script_dir'=>$dir.'/scripts','cron_log_dir'=>$dir.'/cronlogs',
 'web_user'=>posix_getpwuid(posix_geteuid())['name'],'web_group'=>posix_getgrgid(posix_getegid())['name']];
mkdir($dir.'/units'); mkdir($dir.'/cron');
$fake=$dir.'/systemctl'; file_put_contents($fake,"#!/bin/sh\nprintf '%s\\n' \"\$1\" >> '".$dir."/calls'\nexit 0\n");chmod($fake,0700);
$cfg += ['systemctl'=>$fake,'a2ensite'=>$fake,'a2dissite'=>$fake,'apachectl'=>$fake];
try {
 $vhost=new Vhost($cfg,new Paths($cfg['www_root']));
 $body=ManifestImport::apache($conf,$m,$spec,$cfg['www_root']);
 $vhost->applyRepositoryConfig($body,$spec);
 $rendered=file_get_contents($vhost->confPath($spec['name']));
 foreach ([$dir.'/www/new-project/current/dist',$dir.'/www/new-project/current/api/index.php','ServerName new.example.com','UnknownDirective preserve','/etc/ssl/cloudflare/memo.takusumi.work.pem'] as $s) check(str_contains($rendered,$s),'confの適用: '.$s);
 check(!str_contains($rendered,'/var/www/old-project')&&!str_contains($rendered,'/old/ssl.pem'),'旧プロジェクトパスと証明書を差し替え');
 $priorSnapshot=file_get_contents($dir.'/sites/.pjas-inherited-new-project.json');
 $workingScript=file_get_contents($fake);
 file_put_contents($fake,"#!/bin/sh\nif [ \"\$1\" = configtest ]; then exit 1; fi\nexit 0\n");
 rejects(fn()=>$vhost->applyRepositoryConfig(str_replace('preserve','changed',$body),$spec));
 check(file_get_contents($vhost->confPath($spec['name']))===$rendered && file_get_contents($dir.'/sites/.pjas-inherited-new-project.json')===$priorSnapshot,'Apache反映失敗時に本文と継承元の両方を復元');
 file_put_contents($fake,$workingScript);

 rejects(fn()=>ManifestImport::apache($conf."\nInclude /etc/passwd\n",$m,$spec,$cfg['www_root']));
 rejects(fn()=>ManifestImport::apache(str_replace('UnknownDirective preserve','ErrorLog |/bin/sh',$conf),$m,$spec,$cfg['www_root']));
 $token=str_repeat('a',32); $checkout=$dir.'/repos/previews/'.$token;mkdir($checkout.'/deploy',0755,true);
 file_put_contents($checkout.'/.pjas',json_encode($manifest));file_put_contents($checkout.'/deploy/site.conf',$conf);file_put_contents($checkout.'/index.html','reviewed commit');
 file_put_contents($checkout.'.json',json_encode(['repo'=>'https://github.com/owner/repo.git','sha'=>str_repeat('b',40)]));
 (new GitRepositories($cfg))->register('new-project','owner/repo');
 $import=new ManifestImport($cfg);
 $params=['service'=>$spec,'token'=>$token,'json'=>json_encode($manifest),'repo'=>'owner/repo'];
 check($import->validate($params)['valid'],'登録前に読み込み内容を再検証');
 $bad=$params;$bad['repo']='another/repo';rejects(fn()=>$import->validate($bad));
 $task=['id'=>1,'service'=>'new-project','name'=>'clean','schedule'=>'*/5 * * * *','command'=>PjasManifest::command($manifest['cron'][0]['command'],$m,$dir.'/www/new-project'),'enabled'=>1];
 $import->configure($params+['tasks'=>[$task]]);
 $deploy=new Deploy($cfg,new Paths($cfg['www_root']),$vhost,new Health($cfg));
 $source=(new ReflectionMethod($deploy,'validateSource'))->invoke($deploy,$spec,['type'=>'git','ref'=>'main']);
 check($source['manifest_plan']['sha']===str_repeat('b',40),'レビュー済みコミットの取得計画を使用');
 $meta=(new ReflectionMethod($deploy,'fetchGit'))->invoke($deploy,'new-project',$source,$dir.'/release',new \Pjas\Agentd\JobLog(1,$dir.'/jobs'));
 check(file_get_contents($dir.'/release/index.html')==='reviewed commit'&&$meta['sha256']===str_repeat('b',40),'ブランチの更新に左右されず確認済みファイルを配置');
 $import->installPrograms('new-project',static fn($s)=>null);
 check(is_file($dir.'/cron/pjas-task-1')&&str_contains(file_get_contents($dir.'/scripts/task-1.sh'),$dir.'/www/new-project/current/cron/expire.php'),'cronも登録先サイトのcurrentへ追従');
 $registry=json_decode(file_get_contents($dir.'/daemons/registry.json'),true);
 check(count($registry)===1&&reset($registry)['service']==='new-project'&&str_contains(file_get_contents($dir.'/calls'),'enable'),'常駐プログラムを関連付け自動起動を設定');
 $import->installPrograms('new-project',static fn($s)=>null);
 check(count(json_decode(file_get_contents($dir.'/daemons/registry.json'),true))===1,'再実行でデーモンを重複登録しない');
 check($import->plan('new-project')['done']&&!is_dir($checkout),'完了後は初回設定を再適用せず取得キャッシュを削除');
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
