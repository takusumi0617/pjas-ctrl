<?php
declare(strict_types=1);

namespace Pjas\Domain;

/**
 * vhost 生成のプレビュー用。
 *
 * 実際に /etc/apache2 に書き出すのは agent 側の同名ロジック
 * (agent/ops/Vhost.php) であり、そちらが最終的な検証者。
 * ここでの生成は「適用前に人間が差分を確認する」ためのもので、
 * 両者は同じ結果を返さなければならない (tests/VhostParityTest.php)。
 */
final class VhostRenderer
{
    public const DEFAULT_TLS_CERT_FILE = '/etc/ssl/cloudflare/memo.takusumi.work.pem';
    public const DEFAULT_TLS_KEY_FILE  = '/etc/ssl/cloudflare/memo.takusumi.work.key';

    /** 追加ディレクティブとして許可する先頭トークン */
    public const ALLOWED_DIRECTIVES = [
        'Header', 'RequestHeader', 'Redirect', 'RedirectMatch',
        'RewriteEngine', 'RewriteCond', 'RewriteRule',
        'ErrorDocument', 'AddDefaultCharset', 'Options',
        'ProxyPassMatch', 'SetEnvIf', 'Timeout', 'LimitRequestBody',
    ];

    /** @return array<int,string> 不許可だった行 */
    public static function rejectedDirectives(string $extra): array
    {
        $bad = [];
        foreach (preg_split('/\R/', $extra) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // セクション (<Directory> など) と改行注入を禁止
            if (str_starts_with($line, '<')) {
                $bad[] = $line;
                continue;
            }
            $token = strtok($line, " \t");
            if (!in_array($token, self::ALLOWED_DIRECTIVES, true)) {
                $bad[] = $line;
            }
        }
        return $bad;
    }

    public static function render(
        array $s,
        string $wwwRoot = '/var/www',
        string $tlsCertFile = self::DEFAULT_TLS_CERT_FILE,
        string $tlsKeyFile = self::DEFAULT_TLS_KEY_FILE,
    ): string
    {
        $name    = $s['name'];
        $domain  = $s['domain'];
        $docroot = rtrim($wwwRoot . '/' . $name . '/current/' . trim((string) $s['docroot'], '/'), '/');
        $extra   = '';
        foreach (preg_split('/\R/', (string) $s['extra_conf']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $extra .= "    {$line}\n";
            }
        }

        $body  = "    DocumentRoot {$docroot}\n";
        $body .= "    <Directory {$docroot}>\n";
        $body .= "        AllowOverride {$s['allow_override']}\n";
        $body .= "        Require all granted\n";
        $body .= "        Options -Indexes +FollowSymLinks\n";
        $body .= "    </Directory>\n";
        // メンテナンス切替 (agent 側 ops/Vhost.php と同一)
        $shared = $wwwRoot . '/' . $name . '/shared';
        $body .= "    RewriteEngine On\n";
        $body .= "    RewriteCond {$shared}/maintenance.on -f\n";
        $body .= "    RewriteCond %{REQUEST_URI} !^/maintenance\\.html$\n";
        $body .= "    RewriteRule ^ - [R=503,L]\n";
        $body .= "    ErrorDocument 503 /maintenance.html\n";
        $body .= $extra;
        if ($s['runtime'] !== 'static') {
            $sock  = '/run/php/' . $s['runtime'] . '.sock';
            $body .= "    <FilesMatch \\.php$>\n";
            $body .= "        SetHandler \"proxy:unix:{$sock}|fcgi://localhost\"\n";
            $body .= "    </FilesMatch>\n";
        } else {
            $body .= "    <FilesMatch \\.php$>\n        Require all denied\n    </FilesMatch>\n";
        }
        $body .= "    ErrorLog  \${APACHE_LOG_DIR}/{$name}-error.log\n";
        $body .= "    CustomLog \${APACHE_LOG_DIR}/{$name}-access.log combined\n";

        $out  = "# managed by pjas-ctrl -- do not edit by hand\n";
        $out .= "# service: {$name}\n";

        if (!empty($s['tls'])) {
            $out .= "<VirtualHost *:80>\n";
            $out .= "    ServerName {$domain}\n";
            $out .= "    RewriteEngine On\n";
            $out .= "    RewriteCond %{HTTPS} off\n";
            $out .= "    RewriteRule ^/?(.*)$ https://{$domain}/\$1 [R=301,L]\n";
            $out .= "</VirtualHost>\n\n";
            $out .= "<VirtualHost *:443>\n";
            $out .= "    ServerName {$domain}\n";
            $out .= $body;
            $out .= "    SSLEngine on\n";
            $out .= "    SSLCertificateFile    {$tlsCertFile}\n";
            $out .= "    SSLCertificateKeyFile {$tlsKeyFile}\n";
            $out .= "</VirtualHost>\n";
        } else {
            $out .= "<VirtualHost *:80>\n";
            $out .= "    ServerName {$domain}\n";
            $out .= $body;
            $out .= "</VirtualHost>\n";
        }
        return $out;
    }

    /** 単純な行単位 diff (プレビュー表示用) */
    public static function diff(string $old, string $new): array
    {
        $a = preg_split('/\R/', rtrim($old)) ?: [];
        $b = preg_split('/\R/', rtrim($new)) ?: [];
        $out = [];
        $i = $j = 0;
        while ($i < count($a) || $j < count($b)) {
            if ($i < count($a) && $j < count($b) && $a[$i] === $b[$j]) {
                $out[] = [' ', $a[$i]];
                $i++; $j++;
                continue;
            }
            $foundInB = $i < count($a) ? array_search($a[$i], array_slice($b, $j, 8), true) : false;
            if ($foundInB !== false) {
                for ($k = 0; $k < $foundInB; $k++) {
                    $out[] = ['+', $b[$j++]];
                }
                continue;
            }
            if ($i < count($a)) {
                $out[] = ['-', $a[$i++]];
            } elseif ($j < count($b)) {
                $out[] = ['+', $b[$j++]];
            }
        }
        return $out;
    }
}
