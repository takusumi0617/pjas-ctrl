<?php
declare(strict_types=1);require __DIR__.'/support/features.php';
use Pjas\Agentd\{GitHubApp,ControlStore};
[$dir,$cfg]=fixture('github-app');
try{
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$pem);$pub=openssl_pkey_get_details($key)['key'];$calls=[];
 $app=new GitHubApp($cfg,function($method,$path,$bearer,$body)use(&$calls){$calls[]=[$method,$path,$bearer,$body];return str_ends_with($path,'/installation')?['id'=>42]:['token'=>'test-short-lived-access-token'];});
 $status=$app->save(['app_id'=>'123','private_key'=>$pem,'slug'=>'pjas-demo','webhook_secret'=>str_repeat('s',40)]);
 check($status['configured']&&!isset($status['private_key']),'Appの設定を保存し秘密鍵を再表示しない');
 $jwt=GitHubApp::jwt('123',$pem,1700000000);[$h,$p,$signature]=explode('.',$jwt);$decode=static fn($v)=>base64_decode(strtr($v,'-_','+/'));$claims=json_decode($decode($p),true);
 check(openssl_verify($h.'.'.$p,$decode($signature),$pub,OPENSSL_ALGO_SHA256)===1&&$claims['exp']-$claims['iat']===600,'GitHub App JWTをRSA署名し有効期間を制限');
 check($app->token('https://github.com/owner/private.git')==='test-short-lived-access-token','リポジトリのInstallationを使って一時トークンを取得');
 check($calls[0][1]==='/repos/owner/private/installation'&&$calls[1][3]['repositories']===['private']&&$calls[1][3]['permissions']===['contents'=>'read'],'一時トークンを対象リポジトリの読み取り権限に限定');
 $askpass=null;$result=$app->withCredentials('https://github.com/owner/private.git',function($env)use(&$askpass){$askpass=$env['GIT_ASKPASS'];check(is_file($env['PJAS_GITHUB_TOKEN_FILE'])&&(fileperms($env['PJAS_GITHUB_TOKEN_FILE'])&0777)===0600,'Git認証情報を管理者専用の一時ファイルで渡す');return ['code'=>1,'out'=>'','err'=>'test-short-lived-access-token'];});
 check(!file_exists($askpass)&&$result['err']==='[非表示]','取得後は認証情報を削除しエラー出力も伏せる');
 $body=json_encode(['repository'=>['full_name'=>'owner/private'],'ref'=>'refs/heads/main','after'=>str_repeat('a',40)]);$sig='sha256='.hash_hmac('sha256',$body,str_repeat('s',40));
 $verified=$app->verifyWebhook($body,$sig,'push','delivery-1');check($verified['branch']==='main'&&$verified['sha']===str_repeat('a',40),'GitHubの署名と固定コミットを検証');
 rejects(fn()=>$app->verifyWebhook($body.' ',$sig,'push','delivery-2'));
 $path=$dir.'/uploads/'.str_repeat('a',32).'.hook';file_put_contents($path,$body);$app->verifyRequest(['path'=>$path,'signature'=>$sig,'event'=>'push','delivery'=>'delivery-3']);check(!file_exists($path),'大きなWebhookもファイル経由で検証し、処理後に削除');
 check((fileperms($dir.'/control')&0777)===0700,'秘密鍵を保管する管理フォルダは0700');
 rejects(fn()=>$app->token('https://evil.example/owner/private'));
}finally{cleanup($dir);}
