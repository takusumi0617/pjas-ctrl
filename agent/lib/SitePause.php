<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** 停止ページはアプリやリリースから独立したroot所有ファイルに保存する。 */
final class SitePause
{
    public function __construct(private array $cfg) {}
    public function dir(string $name): string
    {
        return rtrim($this->cfg['pause_dir'] ?? '/var/lib/pjas-pauses', '/') . '/' . Spec::name($name);
    }
    public function read(string $name): array
    {
        $path = $this->dir($name) . '/config.json';
        if (is_file($path)) {
            $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!isset($state['page_mode'])) $state['page_mode'] = str_contains($state['html'], '<title>一時停止中</title><h1>ただいま一時停止しています</h1>') ? 'default' : 'custom';
            return $state;
        }
        return ['enabled'=>false,'code'=>503,'page_mode'=>'default','html'=>self::defaultHtml(503)];
    }
    public static function defaultHtml(int $code): string
    {
        [$title,$message] = match ($code) {
            403 => ['アクセスできません','このページへのアクセスは現在制限されています。時間をおいて、もう一度お試しください。'],
            404 => ['ページが見つかりません','お探しのページは現在ご利用いただけません。URLをご確認いただくか、時間をおいてもう一度お試しください。'],
            410 => ['このページの公開は終了しました','お探しのページは公開を終了しているため、現在ご利用いただけません。'],
            503 => ['ただいまメンテナンス中です','よりよいサービスをお届けするため、一時的に公開を停止しています。しばらくしてから、もう一度お越しください。'],
            default => ['サイトは一時停止中です','このサイトは現在ご利用いただけません。時間をおいて、もう一度お試しください。'],
        };
        return strtr((string)file_get_contents(dirname(__DIR__).'/templates/default_pause.html'),['{{CODE}}'=>(string)$code,'{{TITLE}}'=>$title,'{{MESSAGE}}'=>$message]);
    }

    public function validate(array $params): array
    {
        $code = filter_var($params['code'] ?? null, FILTER_VALIDATE_INT);
        if ($code === false || $code < 200 || $code > 599) throw new OpException('HTTPコードは200〜599の整数で入力してください');
        $mode = $params['page_mode'] ?? 'custom';
        if (!in_array($mode,['default','custom'],true)) throw new OpException('停止ページの種類を選んでください');
        $html = $mode === 'default' ? self::defaultHtml($code) : ($params['html'] ?? null);
        if (!is_string($html) || strlen($html) > 128 * 1024 || str_contains($html, "\0")) throw new OpException('HTMLは128KB以内で入力してください');
        return ['enabled' => true, 'code' => $code, 'html' => $html, 'page_mode'=>$mode];
    }
    public function write(string $name, array $state): void
    {
        $dir = $this->dir($name);
        if (is_link($dir)) throw new OpException('停止ページの保存先が不正です');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new OpException('停止ページの保存先を作成できません');
        foreach (['response.php' => file_get_contents(dirname(__DIR__) . '/templates/site_pause.php'),
            'config.json' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)] as $file => $content) {
            $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(8));
            if (file_put_contents($tmp, $content, LOCK_EX) === false) throw new OpException('停止ページを保存できません');
            chmod($tmp, 0644);
            if (!rename($tmp, $dir . '/' . $file)) { @unlink($tmp); throw new OpException('停止ページを保存できません'); }
        }
    }
    public function render(string $normal, array $spec): string
    {
        if (empty($this->read($spec['name'])['enabled'])) return $normal;
        $dir = $this->dir($spec['name']);
        $sock = $this->cfg['pause_fpm_socket'] ?? '/run/php/php8.4-fpm.sock';
        // 独自ProxyPassやリダイレクトを通さず、すべてのURLに停止ページを返す。
        preg_match_all('~<VirtualHost\s+([^>]+)>\s*\R(.*?)</VirtualHost>~is', $normal, $blocks, PREG_SET_ORDER);
        if (!$blocks) throw new OpException('停止用の公開設定を生成できません');
        $out = "# managed by pjas-ctrl -- do not edit by hand\n# paused service: {$spec['name']}\n";
        foreach ($blocks as $block) {
            $body = '    ServerName ' . $spec['domain'] . "\n";
            if (preg_match_all('/^[\t ]*ServerAlias[^\r\n]+/mi', $block[2], $aliases)) $body .= implode("\n", $aliases[0]) . "\n";
            if (preg_match('/:443\b/', $block[1])) {
                $cert = $this->cfg['tls_cert_file'] ?? '/etc/ssl/cloudflare/memo.takusumi.work.pem';
                $key = $this->cfg['tls_key_file'] ?? '/etc/ssl/cloudflare/memo.takusumi.work.key';
                $body .= "    SSLEngine on\n    SSLCertificateFile {$cert}\n    SSLCertificateKeyFile {$key}\n";
            }
            $body .= "    DocumentRoot {$dir}\n    <Directory {$dir}>\n        AllowOverride None\n        Require all granted\n        Options -Indexes -ExecCGI -MultiViews\n        <Files response.php>\n            SetHandler \"proxy:unix:{$sock}|fcgi://localhost\"\n        </Files>\n    </Directory>\n";
            $body .= "    ProxyErrorOverride Off\n    RewriteEngine On\n    RewriteRule !^/?response\\.php$ /response.php [END]\n";
            $body .= "    ErrorLog \${APACHE_LOG_DIR}/{$spec['name']}-error.log\n    CustomLog \${APACHE_LOG_DIR}/{$spec['name']}-access.log combined\n";
            $out .= '<VirtualHost ' . $block[1] . ">\n" . $body . "</VirtualHost>\n";
        }
        return $out;
    }
}
