<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Domain\PjasManifest;
$m=['version'=>2,'site'=>['name'=>'pjas-cert','domain'=>'cert.example.com','runtime'=>'php8.5-fpm','document_root'=>'public'],'components'=>[
 ['id'=>'web','type'=>'php','execution_profile'=>'cert-web','depends_on'=>['ca']],
 ['id'=>'ca','type'=>'worker','execution_profile'=>'cert-ca','command'=>['/usr/bin/php8.5','ca.php'],'storage'=>['ca-state'],'health_checks'=>['ca-ready'],'setup'=>['ca-init']],
]];
$r=PjasManifest::parse(json_encode($m));
check(array_column($r['components'],'id')===['ca','web'],'依存を起動順に整列する');
check(PjasManifest::defaults($r)['shared_paths']==='','v2の既定値は共通sharedを使わない');
foreach ([
 function($m){$m['components'][1]['depends_on']=['web'];return $m;},
 function($m){$m['components'][0]['depends_on']=['unknown'];return $m;},
 function($m){$m['components'][0]['user']='root';return $m;},
 function($m){$m['components'][0]['secrets']=['../../key'];return $m;},
 function($m){$m['components'][1]['command']=['/bin/echo',"x\nUser=root"];return $m;},
 function($m){$m['components'][1]['command']=['/bin/echo','$CREDENTIALS_DIRECTORY'];return $m;},
 function($m){$m['components'][1]['command']=['/bin/echo','%n'];return $m;},
 function($m){$m['components'][1]['working_directory']='../ca';return $m;},
 function($m){$m['components'][]=$m['components'][0];return $m;},
 function($m){$m['components']=array_fill(0,17,$m['components'][0]);return $m;},
 function($m){$m['setup']=['root-script'];return $m;},
 function($m){$m['components'][0]['command']=['/bin/true'];return $m;},
] as $change) rejects(fn()=>PjasManifest::parse(json_encode($change($m))));
check(true,'循環・未知参照・UID指定・パストラバーサル・設定注入・件数超過を拒否する');
check(PjasManifest::parse('{"version":1,"site":{"name":"legacy"}}')['version']===1,'version 1の互換性を維持する');
