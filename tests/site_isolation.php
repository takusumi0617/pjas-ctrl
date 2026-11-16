<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{SiteIsolation,Spec,Paths,ControlStore};
use Pjas\Agentd\Ops\{Vhost,Isolation};
use Pjas\Shared\PhpRuntime;
[$dir,$cfg]=fixture('site-isolation');
try {
    check(PhpRuntime::DEFAULT_RUNTIME==='php8.5-fpm','新規登録の既定はPHP 8.5');
    foreach(['8.4','8.5','8.6'] as $v){
        $runtime='php'.$v.'-fpm';
        check(Spec::service(['name'=>'alpha','runtime'=>$runtime])['runtime']===$runtime,'PHP '.$v.'を両経路で保持');
        check(PhpRuntime::cli($runtime)==='/usr/bin/php'.$v&&PhpRuntime::fpm($runtime)==='/usr/sbin/php-fpm'.$v,'CLIとFPMのパスを共通定義から生成');
    }
    rejects(fn()=>PhpRuntime::cli('php8.6-fpm;id'));
    check(SiteIsolation::identity('alpha')!==SiteIsolation::identity('beta'),'サイトごとに異なるOSアカウント');
    rejects(fn()=>SiteIsolation::identity('../alpha'));
    $spec=Spec::service(['name'=>'alpha','runtime'=>'php8.5-fpm','domain'=>'alpha.example.com','docroot'=>'public']);
    $legacy=(new Vhost($cfg,new Paths($cfg['www_root'])))->render($spec);
    check(str_contains($legacy,'/run/php/php8.5-fpm.sock'),'既存サイトの設定を勝手に隔離へ切り替えない');
    $scoped=array_replace($cfg,['isolation_site'=>'alpha','isolation_runtime'=>'php8.5-fpm','apache_user'=>$cfg['web_user']]);
    $isolated=(new Vhost($scoped,new Paths($cfg['www_root'])))->render($spec);
    check(str_contains($isolated,'/run/php/pjas-alpha-8.5.sock')&&!str_contains($isolated,'/run/php/php8.5-fpm.sock'),'隔離サイトは共通FPMソケットを参照しない');
    file_put_contents($cfg['apache_sites'].'/.pjas-inherited-alpha.json','{}');
    rejects(fn()=>(new Vhost($scoped,new Paths($cfg['www_root'])))->render($spec));
    unlink($cfg['apache_sites'].'/.pjas-inherited-alpha.json');
    $pool=SiteIsolation::pool('alpha','php8.6-fpm',$cfg['web_user'],$cfg['www_root'].'/alpha');
    check(str_contains($pool,'user = '.SiteIsolation::identity('alpha'))&&str_contains($pool,'listen.mode = 0600'),'FPMは専用UIDとApacheのみのソケットを使用');
    check(str_contains($pool,'/alpha/shared/.sessions')&&str_contains($pool,'/alpha/shared/.tmp'),'セッションと一時ファイルもサイト内に分離');
    check(PhpRuntime::command('exec /usr/bin/php8.4 worker.php','php8.6-fpm')==='exec /usr/bin/php8.6 worker.php','常駐処理のPHPを選択バージョンへ統一');
    check(PhpRuntime::command('php artisan config:cache','php8.5-fpm')==='/usr/bin/php8.5 artisan config:cache','暗黙のphpも固定CLIへ統一');
    check(PhpRuntime::renderTemplate('ExecStart=/usr/bin/php8.5 /opt/pjas-ctrl/agent/pjas-agentd.php','8.6')==='ExecStart=/usr/bin/php8.6 /opt/pjas-ctrl/agent/pjas-agentd.php','管理サービスも同じテンプレートで更新');
    $iso=new SiteIsolation($cfg);check($iso->scope('alpha')===$cfg,'未移行サイトは互換構成を維持');
    (new ControlStore($cfg))->write('isolation-alpha',['runtime'=>'php8.5-fpm','status'=>'preparing']);
    rejects(fn()=>$iso->scope('alpha'));
    (new ControlStore($cfg))->write('isolation-alpha',['runtime'=>'php8.5-fpm','status'=>'ready']);
    rejects(fn()=>$iso->scope('alpha'));
    rejects(fn()=>(new Isolation($cfg))->apply(['service'=>$spec,'runtime'=>'php8.6-fpm']));
} finally {cleanup($dir);}
