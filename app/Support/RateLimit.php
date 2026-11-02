<?php
declare(strict_types=1);
namespace Pjas\Support;
final class RateLimit
{
    public static function hit(string $key,int $limit,int $window): bool
    {
        $key=hash('sha256',$key);$now=time();
        Db::run('INSERT INTO login_attempts(bucket,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=CASE WHEN reset_at<? THEN 1 ELSE attempts+1 END, reset_at=CASE WHEN reset_at<? THEN ? ELSE reset_at END',[$key,$now+$window,$now,$now,$now+$window]);
        return (int)Db::one('SELECT attempts FROM login_attempts WHERE bucket=?',[$key])['attempts']<=$limit;
    }
    public static function clear(string $key): void {Db::run('DELETE FROM login_attempts WHERE bucket=?',[hash('sha256',$key)]);}
}
