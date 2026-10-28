<?php
declare(strict_types=1);
namespace Pjas\Support { class Db { public static function one($sql,$args=[]){return null;} } }
namespace {
 foreach (['ValidationException','Hooks','VhostRenderer','ServiceRepo'] as $f)require dirname(__DIR__).'/app/Domain/'.$f.'.php';
 use Pjas\Domain\ServiceRepo;
 function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "ok {$label}\n";}
 $in=['name'=>'new-site','domain'=>'example.com','shared_paths'=>'.env,storage'];
 check(str_contains(ServiceRepo::validate($in,true)['shared_paths'],'@databases'),'新規登録・一括登録でDB自動引き継ぎを初期設定にする');
 check(!str_contains(ServiceRepo::validate($in+['auto_databases_set'=>1],true)['shared_paths'],'@databases'),'新規登録で明示的に自動引き継ぎを解除できる');
 check(!str_contains(ServiceRepo::validate($in,false)['shared_paths'],'@databases'),'既存サイトへ黙って自動引き継ぎを追加しない');
 check(str_contains(ServiceRepo::validate($in+['auto_databases_set'=>1,'auto_databases'=>1],false)['shared_paths'],'@databases'),'既存サイトも設定で有効にできる');
 check(ServiceRepo::sharedPathsText('.env,storage,@databases')==='.env,storage','自動検出の保存用フラグをパス入力欄に表示しない');
}
