<?php
declare(strict_types=1);
namespace Pjas\Http {
    final class Auth {
        public static string $role='owner';
        public static function user():array{return ['email'=>'admin@example.test','role'=>self::$role];}
        public static function can(string $role):bool{return self::$role==='owner'||(self::$role==='operator'&&$role==='operator');}
    }
    final class View {
        public static function e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
        public static function flash():?array{return null;}
    }
    final class Csrf {public static function field():string{return '<input type="hidden" name="csrf" value="fixture">';}}
}
namespace Pjas\Support {final class Config {public static function get(string $key,mixed $default=null):mixed{return $default;}}}
namespace Pjas\Agent {final class AgentClient {public static function alive():bool{return true;}}}
namespace Pjas\Domain {final class ReleaseRepo {public static function current(int $id):array{return ['label'=>'v1.4.0','tag'=>'20261114'];}}}
namespace {
    require_once dirname(__DIR__,2).'/app/Support/Icons.php';
    require_once dirname(__DIR__,2).'/app/Support/Labels.php';
    function uiFixture(string $role='owner',string $request='/services'):string {
        \Pjas\Http\Auth::$role=$role;$_SERVER['REQUEST_URI']=$request;
        $services=[];
        foreach (['pjas-chat','pjas-drive','pjas-memo'] as $i=>$name) $services[]=['id'=>$i+1,'name'=>$name,'domain'=>$name.'.example.test','runtime'=>'php8.5-fpm','tls'=>true,'state'=>'healthy'];
        ob_start();require dirname(__DIR__,2).'/views/services.php';$content=ob_get_clean();
        $title='サイト一覧';ob_start();require dirname(__DIR__,2).'/views/layout.php';return ob_get_clean();
    }
}
