<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once __DIR__.'/ControlStore.php';
final class SiteLifecycleGuard
{
    public static function active(array $cfg,string $name): void
    {
        if(!isset($cfg['control_dir']))return;
        $name=Spec::name($name);
        if(!empty((new ControlStore($cfg))->read('retired-'.$name)))throw new OpException('このサイトは削除処理中、または管理を終了しています。削除済みサイトの画面を確認してください');
    }
}
