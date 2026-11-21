<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';

[$dir,$cfg] = fixture('external-check');
try {
    $stub=$dir.'/curl';
    $script=dirname(__DIR__).'/bin/pjas-external-check';
    $run=static fn(array $args): array => \Pjas\Agentd\Proc::run(
        array_merge(['/bin/sh',$script],$args), null, 5, null, ['PATH'=>$dir.':/usr/bin:/bin']
    );
    file_put_contents($stub,"#!/bin/sh\nprintf '%s' 204\n");
    chmod($stub,0700);
    check($run(['http://example.org/healthz'])['code']===2,'HTTPS以外を拒否する');
    check($run(['https://example.org/healthz','204'])['code']===0,'期待HTTP応答なら成功する');
    check($run(['https://example.org/healthz'])['code']===1,'HTTP応答が異なる場合は失敗する');
    file_put_contents($stub,"#!/bin/sh\nexit 60\n");
    check($run(['https://example.org/healthz'])['code']===1,'TLS検証エラーは失敗扱いにする');
    mkdir($dir.'/credentials');
    mkdir($dir.'/state');
    file_put_contents($dir.'/credentials/discord-url','https://discord.com/api/webhooks/123/fixture');
    file_put_contents($dir.'/http','503');
    file_put_contents($stub, <<<'SH'
#!/bin/sh
case " $* " in
  *" --config - "*)
    cat >/dev/null
    printf '%s\n' "$*" >> "$FIXTURE/sent"
    [ ! -f "$FIXTURE/send-fail" ]
    ;;
  *) cat "$FIXTURE/http" ;;
esac
SH);
    $monitor=static fn(): array => \Pjas\Agentd\Proc::run(
        ['/bin/sh',dirname(__DIR__).'/bin/pjas-external-monitor','https://example.org/healthz','200','ctrl'],
        null, 5, null, ['PATH'=>$dir.':/usr/bin:/bin','FIXTURE'=>$dir,
            'STATE_DIRECTORY'=>$dir.'/state','CREDENTIALS_DIRECTORY'=>$dir.'/credentials']
    );
    touch($dir.'/send-fail');
    check($monitor()['code']===1&&!file_exists($dir.'/state/result'),'送信失敗時に障害通知を再試行できる');
    unlink($dir.'/send-fail');
    check($monitor()['code']===0&&$monitor()['code']===0&&count(file($dir.'/sent'))===2,'継続中の障害は一度だけ通知する');
    file_put_contents($dir.'/http','200');
    check($monitor()['code']===0&&str_contains(file_get_contents($dir.'/sent'),'Service recovered: ctrl')
        &&!str_contains(file_get_contents($dir.'/sent'),'fixture'),'復旧を通知しWebhook認証情報を引数に含めない');
} finally {
    cleanup($dir);
}
