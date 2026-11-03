<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once __DIR__.'/Spec.php';
/** Keep routing and release parents outside the production account's write access. */
final class SiteLayout
{
    public static function protect(array $cfg,string $name):void{
        $name=Spec::name($name);$root=rtrim($cfg['www_root'],'/');$base=$root.'/'.$name;
        clearstatcache(true);
        foreach([$root,$base,$base.'/releases',$base.'/shared'] as $p)if(is_link($p))throw new OpException('管理用フォルダのリンクは使用できません');
        $st=stat($root);if($st['uid']!==posix_geteuid()||($st['mode']&0022))throw new OpException('サイトの親フォルダはroot所有・他ユーザーから書き込み不可にしてください');
        foreach([$base,$base.'/releases'] as $p){if(!is_dir($p)||!chown($p,posix_geteuid())||!chgrp($p,$cfg['web_group'])||!chmod($p,0750))throw new OpException('管理用フォルダを保護できません');}
    }
}
