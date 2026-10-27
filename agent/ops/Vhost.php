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

    /** 有効化済みサイトのディレクトリ (既定では sites-available の隣) */
    public function enabledDir(): string
    {
        return (string) ($this->cfg['apache_sites_enabled']
            ?? dirname(rtrim((string) $this->cfg['apache_sites'], '/')) . '/sites-enabled');
    }

    /**
     * 同じ ServerName / ServerAlias を持つ、pjas-ctrl 管理外の有効サイトを探す。
     *
     * 既存サイトを取り込むとき、旧来の設定 (billing.conf など) が有効なまま残ると
     * 同じ名前の vhost が二重に有効になり、どちらが使われるか読み込み順で決まってしまう。
     * ファイルを触らない判定なのでテストから直接呼べる。
     *
     * @return array<int,string> a2dissite に渡すサイト名 (.conf を除いたもの)
     */
    public static function conflictingSites(string $enabledDir, string $domain, string $selfConf): array
    {
        if (!is_dir($enabledDir)) {
            return [];
        }
        $domain = strtolower(rtrim($domain, '.'));
        $hits   = [];

        foreach (array_diff(scandir($enabledDir) ?: [], ['.', '..']) as $entry) {
            if (!str_ends_with($entry, '.conf') || $entry === basename($selfConf)) {
                continue;
            }
            $body = @file_get_contents($enabledDir . '/' . $entry);   // リンクは追跡される
            if ($body === false || trim($body) === '') {
                continue;
            }
            // pjas-ctrl が書いたものは対象外 (別サービスの設定を落とさない)
            if (str_starts_with($body, self::MANAGED_HEADER)) {
                continue;
            }
            if (preg_match_all('/^\s*Server(?:Name|Alias)\s+(.+)$/mi', $body, $m)) {
                foreach ($m[1] as $line) {
                    foreach (preg_split('/\s+/', trim($line)) ?: [] as $host) {
                        if (strtolower(rtrim($host, '.')) === $domain) {
                            $hits[] = substr($entry, 0, -5);
                            continue 3;
                        }
                    }
                }
            }
        }
        sort($hits);
        return array_values(array_unique($hits));
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
            $cert = (string) ($this->cfg['tls_cert_file'] ?? '/etc/ssl/cloudflare/memo.takusumi.work.pem');
            $key  = (string) ($this->cfg['tls_key_file'] ?? '/etc/ssl/cloudflare/memo.takusumi.work.key');
            $out .= "    SSLCertificateFile    {$cert}\n";
            $out .= "    SSLCertificateKeyFile {$key}\n";
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
            'rendered'  => $this->render($spec),
            'current'   => $cur,
            'managed'   => $cur !== '' && str_starts_with($cur, self::MANAGED_HEADER),
            'path'      => $path,
            'conflicts' => self::conflictingSites($this->enabledDir(), (string) $spec['domain'], $path),
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

        // 同じ ServerName の管理外サイトは、二重定義になるのでここで無効化する。
        // 失敗したときは下の catch で元に戻すため、Apache から見て中途半端な状態にはならない。
        $disabled = [];

        try {
            foreach (self::conflictingSites($this->enabledDir(), (string) $spec['domain'], $path) as $site) {
                Proc::must([$this->a2dissite(), '-q', $site], null, 30);
                $disabled[] = $site;
            }
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
                'disabled'   => $disabled,
            ];
        } catch (\Throwable $e) {
            // 失敗したら直前の内容へ戻し、Apache を安全側に保つ
            if ($backup !== null) {
                file_put_contents($path, $backup);
            } else {
                @unlink($path);
                Proc::run([$this->a2dissite(), '-q', "pjas-{$name}"], null, 30);
            }
            // 無効化した管理外サイトも戻す (元の設定で配信され続けるように)
            foreach ($disabled as $site) {
                Proc::run([$this->cfg['a2ensite'], '-q', $site], null, 30);
            }
            Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
            throw new OpException($e->getMessage() . ' (設定は適用前の状態に戻しました)');
        }
    }

    private function a2dissite(): string
    {
        return (string) ($this->cfg['a2dissite'] ?? '/usr/sbin/a2dissite');
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
