<?php
declare(strict_types=1);
require __DIR__.'/support/ui_fixture.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'ok '.$message."\n";}
$owner=uiFixture();
preg_match_all('~<a class="nav[^>]*>(.*?)</a>~s',$owner,$links);
check(count($links[0])===14,'管理者の全メニューが表示される');
foreach ($links[1] as $link) check(substr_count($link,'<svg ')===1 && trim(strip_tags($link))!=='','各メニューにアイコンと読み上げ可能なラベルがある');
check(substr_count($owner,'aria-current="page"')===1,'選択中のメニューだけに現在位置を表示する');
$viewer=uiFixture('viewer');
foreach (['/components','/users','/retired-sites','/deploy','/audit'] as $restricted) check(!str_contains($viewer,'href="'.$restricted.'"'),'閲覧権限では管理・公開メニューを表示しない: '.$restricted);
check(!str_contains(uiFixture('owner','/services-other'),'aria-current="page"'),'URLの先頭が同じ別ルートを現在位置と誤認しない');
$sprite=file_get_contents(dirname(__DIR__).'/public/assets/icons/material-symbols.svg');
foreach (\Pjas\Support\Icons::NAMES as $name) check(str_contains($sprite,'id="'.$name.'"'),'同梱スプライトにアイコンがある: '.$name);
check(!preg_match('/<script|foreignObject|\bon\w+\s*=|https?:/i',str_replace('http://www.w3.org/2000/svg','',$sprite)),'スプライトにスクリプトや外部参照が含まれない');
try {\Pjas\Support\Icons::render('\" onload=alert(1)');throw new RuntimeException('未登録名を受け入れました');} catch (InvalidArgumentException) {check(true,'未登録・不正なアイコン名を拒否する');}
