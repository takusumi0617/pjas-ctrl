<?php
declare(strict_types=1);
namespace Pjas\Support;

/** Same-origin, fixed Google Material Symbols; labels remain visible text. */
final class Icons
{
    public const NAMES = ['monitoring','home','dns','cloud_upload','history','deployed_code','server_person','settings_ethernet','delete_sweep','manage_accounts','settings','schedule','receipt_long','verified_user','logout','menu','close','folder','link','description','power_settings_new','info','arrow_forward','add','backup','refresh','download','edit','delete','save','check_circle','error','open_in_new','search','content_copy','lock','terminal','code','shield','tune','inventory_2'];

    public static function render(string $name): string
    {
        if (!in_array($name,self::NAMES,true)) throw new \InvalidArgumentException('未登録のアイコンです');
        static $symbols=null;
        if ($symbols===null) {
            $sprite=@file_get_contents(dirname(__DIR__,2).'/public/assets/icons/material-symbols.svg');
            $symbols=[];
            if (is_string($sprite)) {
                preg_match_all('~<symbol id="([a-z0-9_]+)" viewBox="0 -960 960 960">(.*?)</symbol>~s',$sprite,$matches,PREG_SET_ORDER);
                foreach ($matches as $match) $symbols[$match[1]]=$match[2];
            }
        }
        // Inline the shipped paths so strict CSP and external-SVG loading do
        // not hide icons. If assets are absent, visible text still works.
        if (!isset($symbols[$name])) return '';
        return '<svg class="icon" width="20" height="20" viewBox="0 -960 960 960" aria-hidden="true" focusable="false">'.$symbols[$name].'</svg>';
    }
}
