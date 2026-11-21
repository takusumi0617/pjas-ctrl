<?php
declare(strict_types=1);

require __DIR__.'/support/features.php';

use Pjas\Agentd\Proc;
use Pjas\Domain\SecurityGateway;

[$dir] = fixture('security-integration');
try {
    $domains = ['a.example.test'=>'first','b.example.test'=>null];
    $event = ['category'=>'apache','title'=>'Apache HTTP 404（a.example.test:443）'];
    check(SecurityGateway::siteForEvent($event,$domains)==='first','Apacheの記録をドメインの完全一致でサイトに紐付ける');
    check(SecurityGateway::siteForEvent(['category'=>'ssh']+$event,$domains)===null
        && SecurityGateway::siteForEvent(['title'=>'Apache HTTP 404（b.example.test）']+$event,$domains)===null
        && SecurityGateway::siteForEvent(['title'=>'Apache HTTP 404（a.example.test.other）']+$event,$domains)===null,
        '他の分類、同一ドメインの重複登録、部分一致を紐付けない');
    rejects(fn()=>new SecurityGateway('http://localhost'));
    rejects(fn()=>new SecurityGateway('https://example.test/path'));
    rejects(fn()=>new SecurityGateway('https://example.test@other.test'));

    Proc::must(['/usr/bin/openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
        '-keyout',$dir.'/key.pem','-out',$dir.'/cert.pem','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost']);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
        stream_context_create(['ssl'=>['local_cert'=>$dir.'/cert.pem','local_pk'=>$dir.'/key.pem']]));
    if (!$listener) throw new RuntimeException('fixture socket unavailable');
    $port = (int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    $origin = 'https://localhost:'.$port;
    $pid = pcntl_fork();
    if ($pid<0) throw new RuntimeException('fixture fork failed');
    if ($pid===0) {
        try {
            $token = str_repeat('a',43);
            $csrf = str_repeat('b',64);
            for ($i=0; $i<3; ++$i) {
                $socket = stream_socket_accept($listener,5);
                if (!$socket || !stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_SERVER)) exit(2);
                $line = fgets($socket);
                $headers = [];
                while (($header=fgets($socket))!==false && trim($header)!=='') {
                    [$name,$value] = explode(':',$header,2);
                    $headers[strtolower($name)] = trim($value);
                }
                $body = isset($headers['content-length']) && (int)$headers['content-length']>0
                    ? json_decode(stream_get_contents($socket,(int)$headers['content-length']),true) : [];
                if ($i===0) {
                    if ($line!=="POST /api/v1/auth/login HTTP/1.1\r\n" || $headers['origin']!==$origin || $body['code']!=='123456') exit(3);
                    $response = "HTTP/1.1 200 OK\r\nSet-Cookie: pjas_session=$token; Secure; HttpOnly\r\n";
                    $data = json_encode(['csrf_token'=>$csrf]);
                } else {
                    if (($headers['cookie']??'')!=='pjas_session='.$token || ($headers['x-csrf-token']??'')!==$csrf
                        || $headers['origin']!==$origin || $body['digest']!==str_repeat('c',64)) exit(4);
                    $response = $i===1 ? "HTTP/1.1 200 OK\r\n" : "HTTP/1.1 302 Found\r\nLocation: https://example.invalid/\r\n";
                    $data = '{"status":"approved"}';
                }
                fwrite($socket,$response.'Content-Length: '.strlen($data)."\r\nConnection: close\r\n\r\n".$data);
                fclose($socket);
            }
        } finally {
            fclose($listener);
        }
        exit(0);
    }
    fclose($listener);
    putenv('SSL_CERT_FILE='.$dir.'/cert.pem');
    $client = new SecurityGateway($origin);
    $client->request('auth/login',['username'=>'admin','password'=>'fixture','code'=>'123456'],[],$session);
    $body = ['digest'=>str_repeat('c',64),'password'=>'fixture','code'=>'123456'];
    $path = 'defense/proposals/00000000-0000-0000-0000-000000000000/approve';
    check($client->request($path,$body,$session)['status']==='approved','HTTPS経由でセッション、CSRF、Originと承認ハッシュを送信する');
    try {
        $client->request($path,$body,$session);
        throw new RuntimeException('redirect accepted');
    } catch (RuntimeException $error) {
        check($error->getCode()===302,'リダイレクトへセッションと認証情報を転送しない');
    }
    pcntl_waitpid($pid,$status);
    check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'HTTPSのAPI契約を満たす');
} finally {
    cleanup($dir);
}
