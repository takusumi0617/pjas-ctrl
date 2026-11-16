<?php
declare(strict_types=1);
$project=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($project):void{
 if(str_starts_with($class,'Pjas\\Agentd\\Ops\\'))$file=$project.'/agent/ops/'.substr($class,16).'.php';
 elseif(str_starts_with($class,'Pjas\\Agentd\\'))$file=$project.'/agent/lib/'.substr($class,12).'.php';
 elseif(str_starts_with($class,'Pjas\\Shared\\'))$file=$project.'/shared/'.substr($class,12).'.php';
 elseif(str_starts_with($class,'Pjas\\'))$file=$project.'/app/'.str_replace('\\','/',substr($class,5)).'.php';else return;
 if(is_file($file))require_once $file;
});
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'ok '.$label."\n";}
function rejects(callable $fn):void{try{$fn();}catch(Throwable $e){if($e instanceof RuntimeException||$e instanceof InvalidArgumentException)return;throw $e;}throw new Exception('拒否されませんでした');}
function cleanup(string $dir):void{if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
function fixture(string $name):array{
 $dir=sys_get_temp_dir().'/pjas-'.$name.'-'.bin2hex(random_bytes(6));mkdir($dir,0700);
 foreach(['www','sites','enabled','jobs','uploads','cron','scripts','cronlogs','daemons','units','apachelogs'] as $p)mkdir($dir.'/'.$p,0755);
 $user=posix_getpwuid(posix_geteuid())['name'];$group=posix_getgrgid(posix_getegid())['name'];$cmd=$dir.'/command';file_put_contents($cmd,"#!/bin/sh\nexit 0\n");chmod($cmd,0700);
 $cfg=['control_dir'=>$dir.'/control','www_root'=>$dir.'/www','apache_sites'=>$dir.'/sites','apache_sites_enabled'=>$dir.'/enabled','job_dir'=>$dir.'/jobs','upload_dir'=>$dir.'/uploads','github_repo_dir'=>$dir.'/repositories',
 'web_user'=>$user,'web_group'=>$group,'apachectl'=>$cmd,'a2ensite'=>$cmd,'a2dissite'=>$cmd,'systemctl'=>$cmd,'daemon_dir'=>$dir.'/daemons','daemon_unit_dir'=>$dir.'/units','cron_dir'=>$dir.'/cron','cron_script_dir'=>$dir.'/scripts','cron_log_dir'=>$dir.'/cronlogs','apache_log_dir'=>$dir.'/apachelogs','pause_dir'=>$dir.'/pauses','preview_root'=>$dir.'/previews','preview_user'=>$user,'php_fpm_bin'=>$cmd,'preview_fpm_bin'=>$cmd,'health_retries'=>0,'health_wait'=>0,'hook_timeout'=>10];
 return [$dir,$cfg];
}
function webDb(string $dir):void{
 $file=$dir.'/config.php';file_put_contents($file,'<?php return '.var_export(['db'=>$dir.'/app.db','security_key'=>$dir.'/security.key','job_dir'=>$dir.'/jobs','upload_dir'=>$dir.'/uploads','timezone'=>'Asia/Tokyo','agent_socket'=>$dir.'/agent.sock'],true).';');
 Pjas\Support\Config::load($file);Pjas\Support\Db::migrate(dirname(__DIR__,2).'/db/schema.sql');
}
