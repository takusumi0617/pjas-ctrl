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
} finally {
    cleanup($dir);
}
