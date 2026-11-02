<?php
declare(strict_types=1);
namespace Pjas\Http\Controllers;
use Pjas\Agent\{AgentClient,AgentException};
use Pjas\Domain\Automation;
final class WebhookController
{
    public function receive(): void
    {
        header('Content-Type: application/json');header('Cache-Control: no-store');
        if(!\Pjas\Support\RateLimit::hit('webhook:'.($_SERVER['REMOTE_ADDR']??''),120,60)){http_response_code(429);echo '{"ok":false}';return;}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>25*1024*1024){http_response_code(413);echo '{"ok":false}';return;}
        $root=rtrim((string)\Pjas\Support\Config::get('upload_dir'),'/');if(!is_dir($root))mkdir($root,0770,true);$path=$root.'/'.bin2hex(random_bytes(16)).'.hook';
        $in=fopen('php://input','rb');$out=fopen($path,'xb');chmod($path,0640);$bytes=stream_copy_to_stream($in,$out,25*1024*1024+1);fclose($in);fclose($out);
        if($bytes===false||$bytes>25*1024*1024){@unlink($path);http_response_code(413);echo '{"ok":false}';return;}
        try{$verified=AgentClient::call('github.webhook.verify',['path'=>$path,'signature'=>$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'','event'=>$_SERVER['HTTP_X_GITHUB_EVENT']??'','delivery'=>$_SERVER['HTTP_X_GITHUB_DELIVERY']??''],'GitHub Webhook');
            $count=Automation::webhook($verified);http_response_code(202);echo json_encode(['ok'=>true,'queued'=>$count]);
        }catch(AgentException $e){http_response_code(str_contains($e->getMessage(),'署名')?403:503);echo '{"ok":false,"message":"signature or connection invalid"}';}
        finally{@unlink($path);}
    }
}
