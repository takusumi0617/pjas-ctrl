<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Support\Db;
use Pjas\Http\Auth;
final class PublicationQueue
{
    public static function reserve(array $site,array $source,string $utc,int $user,string $actor): int
    {
        if(!Auth::userCanSite($user,(int)$site['id'],'operator'))throw new \InvalidArgumentException('このサイトの公開権限がありません');
        return Db::insert('INSERT INTO publication_queue(service_id,requested_by,actor,source,publish_at) VALUES (?,?,?,?,?)',[$site['id'],$user,$actor,json_encode($source,JSON_THROW_ON_ERROR),$utc]);
    }
    public static function cancel(int $id,int $service): void
    {
        if(Db::run("UPDATE publication_queue SET state='cancelled',detail='利用者が取り消しました' WHERE id=? AND service_id=? AND state='queued'",[$id,$service])!==1)throw new \InvalidArgumentException('開始済みか、取り消せない予約です');
    }
    public static function forSite(int $id): array {return Db::all('SELECT * FROM publication_queue WHERE service_id=? ORDER BY id DESC LIMIT 30',[$id]);}
}
