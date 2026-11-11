<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** Per-site TLS selection, kept outside the application database. */
final class SiteTls
{
    public static function paths(array $cfg,string $site): array
    {
        $root=rtrim($cfg['control_dir']??'/var/lib/pjas-control','/');
        $state=$root.'/tls-'.Spec::name($site).'.json';
        if (!is_file($state)) return ['cert'=>$cfg['tls_cert_file']??'/etc/ssl/cloudflare/memo.takusumi.work.pem','key'=>$cfg['tls_key_file']??'/etc/ssl/cloudflare/memo.takusumi.work.key'];
        $s=(new ControlStore($cfg))->read('tls-'.$site);
        if (($s['provider']??'common')==='common') return ['cert'=>$cfg['tls_cert_file'],'key'=>$cfg['tls_key_file']];
        return ['cert'=>$root.'/tls/'.$site.'/current/certificate.pem','key'=>$root.'/tls/'.$site.'/current/key.pem'];
    }
    public static function decorate(array $cfg,string $site,string $body): string
    {
        $state=rtrim($cfg['control_dir']??'/var/lib/pjas-control','/').'/tls-'.Spec::name($site).'.json';
        if (!is_file($state)) return $body;
        $paths=self::paths($cfg,$site);
        $body=preg_replace('/^([ \t]*SSLCertificateFile)[ \t]+[^\r\n]+/mi','$1 '.$paths['cert'],$body);
        return preg_replace('/^([ \t]*SSLCertificateKeyFile)[ \t]+[^\r\n]+/mi','$1 '.$paths['key'],$body);
    }
}
