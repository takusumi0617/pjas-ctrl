<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;

/**
 * vhost の生成と適用。/etc/apache2 に書けるのはここだけ。
 * 受け取るのは検証済みの仕様であって、設定ファイル本文ではない
 * (本文を受け取ると「任意の Apache 設定を書ける = 実質 root」になるため)。
 */
final class Vhost
{
    public const MANAGED_HEADER = '# managed by pjas-ctrl -- do not edit by hand';

    public function __construct(private array $cfg, private Paths $paths)
    {
    }

    public function confPath(string $name): string
    {
        return rtrim((string) $this->cfg['apache_sites'], '/') . "/pjas-{$name}.conf";
    }

    public function render(array $spec): string
    {
        $name    = $spec['name'];
        $domain  = $spec['domain'];
        $docroot = rtrim($this->paths->current($name) . '/' . $spec['docroot'], '/');
        $shared  = $this->paths->shared($name);

        $body  = "    DocumentRoot {$docroot}\n";
        $body .= "    <Directory {$docroot}>\n";
        $body .= "        AllowOverride {$spec['allow_override']}\n";
        $body .= "        Require all granted\n";
        $body .= "        Options -Indexes +FollowSymLinks\n";
        $body .= "    </Directory>\n";
        // メンテナンス切替: shared/maintenance.on があれば 503 を返す
        $body .= "    RewriteEngine On\n";
        $body .= "    RewriteCond {$shared}/maintenance.on -f\n";
        $body .= "    RewriteCond %{REQUEST_URI} !^/maintenance\\.html$\n";
        $body .= "    RewriteRule ^ - [R=503,L]\n";
        $body .= "    ErrorDocument 503 /maintenance.html\n";
        foreach (explode("\n", $spec['extra_conf']) as $line) {
            if (trim($line) !== '') {
                $body .= '    ' . trim($line) . "\n";
            }
        }
        if ($spec['runtime'] !== 'static') {
            $sock  = '/run/php/' . $spec['runtime'] . '.sock';
            $body .= "    <FilesMatch \\.php$>\n";
            $body .= "        SetHandler \"proxy:unix:{$sock}|fcgi://localhost\"\n";
            $body .= "    </FilesMatch>\n";
        } else {
            $body .= "    <FilesMatch \\.php$>\n        Require all denied\n    </FilesMatch>\n";
        }
        $body .= "    ErrorLog  \${APACHE_LOG_DIR}/{$name}-error.log\n";
        $body .= "    CustomLog \${APACHE_LOG_DIR}/{$name}-access.log combined\n";

        $out = self::MANAGED_HEADER . "\n# service: {$name}\n";
        if ($spec['tls']) {
            $out .= "<VirtualHost *:80>\n    ServerName {$domain}\n";
            $out .= "    RewriteEngine On\n    RewriteCond %{HTTPS} off\n";
            $out .= "    RewriteRule ^/?(.*)$ https://{$domain}/\$1 [R=301,L]\n</VirtualHost>\n\n";
            $out .= "<VirtualHost *:443>\n    ServerName {$domain}\n" . $body;
            $out .= "    SSLEngine on\n";
            $out .= "    SSLCertificateFile    /etc/letsencrypt/live/{$domain}/fullchain.pem\n";
            $out .= "    SSLCertificateKeyFile /etc/letsencrypt/live/{$domain}/privkey.pem\n";
            $out .= "</VirtualHost>\n";
        } else {
            $out .= "<VirtualHost *:80>\n    ServerName {$domain}\n" . $body . "</VirtualHost>\n";
        }
        return $out;
    }

    /** 現行ファイルと生成結果を返す (書き込みはしない) */
    public function preview(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $path = $this->confPath($spec['name']);
        $cur  = is_file($path) ? (string) file_get_contents($path) : '';

        return [
            'rendered' => $this->render($spec),
            'current'  => $cur,
            'managed'  => $cur !== '' && str_starts_with($cur, self::MANAGED_HEADER),
            'path'     => $path,
        ];
    }

    /** 書き出し → a2ensite → configtest → graceful。失敗したら元に戻す。 */
    public function apply(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $name = $spec['name'];
        $path = $this->confPath($name);
        $conf = $this->render($spec);

        $backup = is_file($path) ? (string) file_get_contents($path) : null;

        if (file_put_contents($path, $conf) === false) {
            throw new OpException("設定ファイルを書き出せません: {$path}");
        }
        chmod($path, 0644);

        try {
            Proc::must([$this->cfg['a2ensite'], '-q', "pjas-{$name}"], null, 30);
            $test = Proc::run([$this->cfg['apachectl'], 'configtest'], null, 30);
            if ($test['code'] !== 0) {
                throw new OpException('configtest 失敗: ' . trim($test['err'] ?: $test['out']));
            }
            $reload = Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
            if ($reload['code'] !== 0) {
                throw new OpException('graceful 失敗: ' . trim($reload['err'] ?: $reload['out']));
            }
            return [
                'hash'       => hash('sha256', $conf),
                'configtest' => trim($test['err'] ?: $test['out']) ?: 'Syntax OK',
                'path'       => $path,
            ];
        } catch (\Throwable $e) {
            // 失敗したら直前の内容へ戻し、Apache を安全側に保つ
            if ($backup !== null) {
                file_put_contents($path, $backup);
            } else {
                @unlink($path);
                Proc::run(['/usr/sbin/a2dissite', '-q', "pjas-{$name}"], null, 30);
            }
            Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
            throw new OpException($e->getMessage() . ' (設定は適用前の状態に戻しました)');
        }
    }

    public function reload(): array
    {
        $test = Proc::run([$this->cfg['apachectl'], 'configtest'], null, 30);
        if ($test['code'] !== 0) {
            throw new OpException('configtest 失敗: ' . trim($test['err'] ?: $test['out']));
        }
        Proc::must([$this->cfg['apachectl'], 'graceful'], null, 60);
        return ['ok' => true];
    }
}
