<?php
declare(strict_types=1);
namespace Pjas\Support;
final class Time
{
    public static function display(?string $value): string {if(!$value)return '未実行';try{return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone((string)Config::get('timezone','Asia/Tokyo')))->format('Y-m-d H:i');}catch(\Throwable){return $value;}}
    public static function reservation(string $local,?int $now=null): string
    {
        $zone=new \DateTimeZone((string)Config::get('timezone','Asia/Tokyo'));$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,$zone);$errors=\DateTimeImmutable::getLastErrors();
        if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i')!==$local||$date->getTimestamp()<($now??time())+60||$date->getTimestamp()>($now??time())+30*86400)throw new \InvalidArgumentException('予約日時は1分後から30日以内の日時で指定してください');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
