<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Support\Db;
final class SitePolicy
{
    public static function get(int $id): array
    {
        $row=Db::one('SELECT settings FROM site_policies WHERE service_id=?',[$id]);$settings=$row?json_decode($row['settings'],true):[];
        return array_replace(['backup_enabled'=>false,'backup_hours'=>24,'retention_count'=>14,'retention_days'=>30,'notifications'=>true,'webhook_enabled'=>false,'branch'=>'main','backup_last'=>0],$settings?:[]);
    }
    public static function save(int $id,array $in,int $user): void
    {
        $old=self::get($id);$branch=GitHubRepository::ref((string)($in['branch']??'main'));if(!empty($in['webhook_enabled'])&&$branch==='HEAD')throw new \InvalidArgumentException('自動公開ではmainなどのブランチ名を指定してください');
        $settings=array_replace($old,['backup_enabled'=>!empty($in['backup_enabled']),'backup_hours'=>max(1,min(720,(int)($in['backup_hours']??24))),
            'retention_count'=>max(2,min(100,(int)($in['retention_count']??14))),'retention_days'=>max(1,min(365,(int)($in['retention_days']??30))),
            'notifications'=>!empty($in['notifications']),'webhook_enabled'=>!empty($in['webhook_enabled']),'branch'=>$branch]);
        self::write($id,$settings,$user);
    }
    public static function write(int $id,array $settings,?int $user=null): void {Db::run("INSERT INTO site_policies(service_id,settings,updated_by) VALUES (?,?,?) ON CONFLICT(service_id) DO UPDATE SET settings=excluded.settings,updated_by=COALESCE(excluded.updated_by,site_policies.updated_by),updated_at=datetime('now')",[$id,json_encode($settings,JSON_THROW_ON_ERROR),$user]);}
}
