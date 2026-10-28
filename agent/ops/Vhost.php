<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

require_once dirname(__DIR__, 2) . '/shared/SiteErrors.php';
use Pjas\Shared\SiteErrors;

use Pjas\Agentd\OpException;
use Pjas\Agentd\InheritedVhost;
use Pjas\Agentd\SitePause;
require_once dirname(__DIR__) . '/lib/SitePause.php';
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

    private function inheritedPath(string $name): string
    {
        return rtrim((string) $this->cfg['apache_sites'], '/') . '/.pjas-inherited-' . Spec::name($name) . '.json';
    }

    private function inheritedRender(string $body, array $spec, ?string $sourceDomain = null): string
    {
        return InheritedVhost::render($body, $spec, $this->paths->current($spec['name']),
            $this->cfg['tls_cert_file'] ?? '/etc/ssl/cloudflare/memo.takusumi.work.pem',
            $this->cfg['tls_key_file'] ?? '/etc/ssl/cloudflare/memo.takusumi.work.key', $sourceDomain);
    }

    private function migrationSource(array $params, array $spec): ?array
    {
        $source = $params['migration_source'] ?? '';
        if ($source === '') return null;
        if (is_file($this->inheritedPath($spec['name']))) throw new OpException('このサイトはすでに設定を継承済みです');
        if (!is_string($source) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.conf\z/D', $source)) throw new OpException('移行元ファイル名が不正です');
        $dir = realpath($this->cfg['apache_sites']);
        $path = realpath($this->cfg['apache_sites'] . '/' . $source);
        if (!$dir || !$path || dirname($path) !== $dir || !is_file($path)) throw new OpException('移行元は sites-available の設定ファイルを選んでください');
        $stat = stat($path);
        if ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022) !== 0) {
            throw new OpException('移行元は root 所有で、他のユーザーが書き込めない設定ファイルにしてください');
        }
        $body = (string) file_get_contents($path);
        if (str_starts_with($body, self::MANAGED_HEADER)) throw new OpException('管理下の生成済み設定は移行元に選べません');
        $rendered = (new SitePause($this->cfg))->render(SiteErrors::decorate($this->inheritedRender($body, $spec)), $spec);
        return ['source' => $source, 'domain' => $spec['domain'], 'body' => $body, 'hash' => hash('sha256', $body), 'rendered' => $rendered];
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
        return (new SitePause($this->cfg))->render(SiteErrors::decorate($this->renderNormal($spec)), $spec);
    }

    private function renderNormal(array $spec): string
    {
        $saved = $this->inheritedPath($spec['name']);
        if (is_file($saved)) {
            $snapshot = json_decode((string) file_get_contents($saved), true, 512, JSON_THROW_ON_ERROR);
            return $this->inheritedRender($snapshot['body'], $spec, $snapshot['domain']);
        }
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
        foreach (explode("\n", $spec['extra_conf']) as $line) {
            if (trim($line) !== '') {
                $body .= '    ' . strtr(trim($line), ['{{site_root}}' => $this->paths->current($name), '{{document_root}}' => $docroot]) . "\n";
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

    public function applyRepositoryConfig(string $body, array $spec): array
    {
        $lock = fopen($this->cfg['apache_sites'] . '/.pjas-vhost.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new OpException('公開設定をロックできません');
        $path = $this->inheritedPath($spec['name']);
        $old = is_file($path) ? file_get_contents($path) : null;
        try {
            if (!preg_match('/^\s*ServerName\s+(\S+)/mi',$body,$host)) throw new OpException('ServerNameがありません');
            $snapshot = ['body'=>$body,'domain'=>$host[1],'source'=>'.pjas'];
            $this->inheritedRender($body,$spec,$host[1]);
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
            if (file_put_contents($tmp,json_encode($snapshot,JSON_THROW_ON_ERROR)) === false) throw new OpException('Apache設定を保存できません');
            chmod($tmp,0600); if (!rename($tmp,$path)) throw new OpException('Apache設定を保存できません');
            try { return $this->applyUnlocked(['service'=>$spec]); }
            catch (\Throwable $e) { if ($old === null) @unlink($path); else file_put_contents($path,$old); throw $e; }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public function pauseStatus(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $state = (new SitePause($this->cfg))->read($spec['name']);
        $path = $this->confPath($spec['name']);
        $state['can_pause'] = is_file($path) && str_starts_with((string) file_get_contents($path), self::MANAGED_HEADER);
        return $state;
    }

    public function pause(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $action = $params['action'] ?? '';
        if (!in_array($action, ['pause', 'resume'], true)) throw new OpException('停止・再開の操作が不正です');
        $lock = fopen($this->cfg['apache_sites'] . '/.pjas-vhost.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new OpException('公開設定をロックできません');
        $pause = new SitePause($this->cfg);
        try {
            if (!$this->pauseStatus(['service' => $spec])['can_pause']) throw new OpException('先にこのサイトの公開設定を反映してください。独自設定がある場合は既存confを継承してから操作してください');
            $old = $pause->read($spec['name']);
            $state = $action === 'pause' ? $pause->validate($params) : array_replace($old, ['enabled' => false]);
            try {
                $pause->write($spec['name'], $state);
                $result = $this->applyUnlocked(['service' => $spec]);
                return $result + ['enabled' => $state['enabled'], 'code' => $state['code']];
            } catch (\Throwable $e) {
                $pause->write($spec['name'], $old);
                throw $e;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function updateMode(array $params): string
    {
        $mode = $params['update_mode'] ?? 'template';
        if (!in_array($mode, ['errors', 'template'], true)) throw new OpException('更新する内容を選んでください');
        return $mode;
    }

    private function currentForErrors(array $spec): string
    {
        $path = $this->confPath($spec['name']);
        if (!is_file($path) || is_link($path)) throw new OpException('先に設定全体を反映してください。更新する公開設定ファイルがありません');
        $stat = stat($path);
        if ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022)) throw new OpException('公開設定はroot所有で、他のユーザーが書き込めない状態にしてください');
        $conf = (string) file_get_contents($path);
        if (!preg_match('/^[\t ]*<VirtualHost\s+[^>]+>[^\S\r\n]*\R/mi', $conf)) throw new OpException('更新するVirtualHostが見つかりません');
        return $conf;
    }

    /** 現在のconfだけを入力とし、再生成・有効化・継承元の変更を行わない。 */
    private function applyErrors(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $path = $this->confPath($spec['name']);
        $old = $this->currentForErrors($spec);
        if (!hash_equals(hash('sha256', $old), (string) ($params['current_hash'] ?? ''))) {
            throw new OpException('公開設定がプレビュー後に変更されています。画面を開き直して変更点を確認してください');
        }
        $conf = SiteErrors::decorate($old);
        $mode = fileperms($path) & 0777;
        $write = static function (string $body) use ($path, $mode): void {
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
            if (file_put_contents($tmp, $body, LOCK_EX) === false) throw new OpException('公開設定を保存できません');
            chmod($tmp, $mode);
            if (!rename($tmp, $path)) { @unlink($tmp); throw new OpException('公開設定を保存できません'); }
        };
        $write($conf);
        try {
            $this->reload();
        } catch (\Throwable $e) {
            $write($old);
            Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
            throw new OpException($e->getMessage() . ' (公開設定は更新前の内容に戻しました)');
        }
        return ['hash'=>hash('sha256', $conf), 'path'=>$path, 'configtest'=>'Syntax OK', 'disabled'=>[], 'update_mode'=>'errors'];
    }

    /** 現行ファイルと生成結果を返す (書き込みはしない) */
    public function preview(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $path = $this->confPath($spec['name']);
        $cur  = is_file($path) ? (string) file_get_contents($path) : '';

        if ($this->updateMode($params) === 'errors') {
            $current = $cur === '' ? '' : $this->currentForErrors($spec);
            return ['rendered'=>$current === '' ? '' : SiteErrors::decorate($current), 'current'=>$current,
                'current_hash'=>hash('sha256', $current), 'errors_available'=>$current !== '',
                'managed'=>str_starts_with($current, self::MANAGED_HEADER), 'conflicts'=>[],
                'inherited'=>is_file($this->inheritedPath($spec['name'])), 'migration_sources'=>[]];
        }
        $migration = $this->migrationSource($params, $spec);
        $sources = [];
        if (!is_file($this->inheritedPath($spec['name']))) {
            foreach (glob($this->cfg['apache_sites'] . '/*.conf') ?: [] as $candidate) {
                try {
                    $m = $this->migrationSource(['migration_source' => basename($candidate)], $spec);
                    $sources[] = $m['source'];
                } catch (OpException $e) { /* 不一致・複合サイトは候補に含めない */ }
            }
        }
        return [
            'migration_sources' => $sources,
            'migration_source' => $migration['source'] ?? '',
            'migration_hash' => $migration['hash'] ?? '',
            'inherited' => is_file($this->inheritedPath($spec['name'])),
            'rendered'  => $migration['rendered'] ?? $this->render($spec),
            'current'   => $migration['body'] ?? $cur,
            'managed'   => $cur !== '' && str_starts_with($cur, self::MANAGED_HEADER),
            'path'      => $path,
            'conflicts' => self::conflictingSites($this->enabledDir(), (string) $spec['domain'], $path),
        ];
    }

    /** 書き出し → a2ensite → configtest → graceful。失敗したら元に戻す。 */
    public function apply(array $params): array
    {
        $lock = fopen($this->cfg['apache_sites'] . '/.pjas-vhost.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new OpException('公開設定をロックできません');
        try { return $this->updateMode($params) === 'errors' ? $this->applyErrors($params) : $this->applyUnlocked($params); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function applyUnlocked(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $name = $spec['name'];
        $path = $this->confPath($name);
        $migration = $this->migrationSource($params, $spec);
        if ($migration !== null && !hash_equals($migration['hash'], (string) ($params['migration_hash'] ?? ''))) {
            throw new OpException('移行元が変更されたか、プレビューが未確認です。もう一度プレビューを開いてください');
        }
        $conf = $migration['rendered'] ?? $this->render($spec);

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
            if ($migration !== null) {
                $snapshotPath = $this->inheritedPath($name);
                $tmp = $snapshotPath . '.tmp-' . bin2hex(random_bytes(8));
                if (file_put_contents($tmp, json_encode($migration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
                    throw new OpException('継承設定を保存できません');
                }
                chmod($tmp, 0600);
                if (!rename($tmp, $snapshotPath)) { @unlink($tmp); throw new OpException('継承設定を保存できません'); }
            }
            return [
                'hash'       => hash('sha256', $conf),
                'configtest' => trim($test['err'] ?: $test['out']) ?: 'Syntax OK',
                'path'       => $path,
                'disabled'   => $disabled,
            ];
        } catch (\Throwable $e) {
            if ($migration !== null) @unlink($this->inheritedPath($name));
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
