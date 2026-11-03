<?php
declare(strict_types=1);
namespace Pjas\Shared;
final class SensitiveFiles
{
    public const BLOCK="    <IfFile /etc/apache2/conf-available/pjas-sensitive-files.conf>\n        Include /etc/apache2/conf-available/pjas-sensitive-files.conf\n    </IfFile>\n";
    public static function decorate(string $conf):string{
        $conf=str_replace(self::BLOCK,'',$conf);
        return preg_replace_callback('~^[\\t ]*</VirtualHost>~mi',static fn($m)=>self::BLOCK.$m[0],$conf);
    }
}
