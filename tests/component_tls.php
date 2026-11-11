<?php
declare(strict_types=1);
require __DIR__.'/support/features.php';
use Pjas\Agentd\{Proc,SiteTls};
use Pjas\Agentd\Ops\Certificates;
[$dir,$cfg]=fixture('component-tls');
try {
 Proc::must(['/usr/bin/openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',$dir.'/key','-out',$dir.'/cert','-days','2','-subj','/CN=cert.example.com','-addext','subjectAltName=DNS:cert.example.com','-addext','extendedKeyUsage=serverAuth']);
 $r=Certificates::validate($dir.'/cert',$dir.'/key','cert.example.com',$dir.'/cert');check(strlen($r['fingerprint'])===64,'SAN・期間・用途・信頼チェーン・鍵の一致を検証する');
 rejects(fn()=>Certificates::validate($dir.'/cert',$dir.'/key','other.example.com',$dir.'/cert'));
 Proc::must(['/usr/bin/openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',$dir.'/otherkey']);
 rejects(fn()=>Certificates::validate($dir.'/cert',$dir.'/otherkey','cert.example.com',$dir.'/cert'));
 rejects(fn()=>Certificates::validate($dir.'/cert',$dir.'/key','cert.example.com',$dir.'/cert',86400*3));
 Proc::must(['/usr/bin/openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',$dir.'/clientkey','-out',$dir.'/client','-days','2','-subj','/CN=cert.example.com','-addext','subjectAltName=DNS:cert.example.com','-addext','extendedKeyUsage=clientAuth']);
 rejects(fn()=>Certificates::validate($dir.'/client',$dir.'/clientkey','cert.example.com',$dir.'/client'));
 $body="SSLCertificateFile /common.pem\nSSLCertificateKeyFile /common.key\n";check(SiteTls::decorate($cfg,'legacy',$body)===$body,'既存サイトの共通証明書の既定値を維持する');
 check(true,'SAN不一致・鍵不一致・残存期間不足・用途違反を拒否する');
} finally {cleanup($dir);}
