<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Support\Db;
final class SettingsChanges
{
    public static function pending(int $id): ?array
    {
        $row=Db::one('SELECT value FROM app_state WHERE name=?',['settings.pending.'.$id]);
        return $row?json_decode($row['value'],true,512,JSON_THROW_ON_ERROR):null;
    }
    public static function record(int $id,array $before,array $after): void
    {
        $changed=[];foreach($after as $key=>$value)if((string)($before[$key]??'')!==(string)$value)$changed[]=$key;
        if(!$changed)return;
        $old=self::pending($id);$fields=array_values(array_unique(array_merge($old['fields']??[],$changed)));
        Db::run('INSERT INTO app_state(name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value',['settings.pending.'.$id,json_encode(['fields'=>$fields,'saved_at'=>gmdate('c'),'revision'=>bin2hex(random_bytes(12))],JSON_THROW_ON_ERROR)]);
    }
    public static function clearIfCurrent(int $id,array $expected): void {Db::run('DELETE FROM app_state WHERE name=? AND value=?',['settings.pending.'.$id,json_encode($expected,JSON_THROW_ON_ERROR)]);}
    public static function clear(int $id): void {Db::run('DELETE FROM app_state WHERE name=?',['settings.pending.'.$id]);}
}
