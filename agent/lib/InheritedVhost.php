<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** 本文は移行元のサーバー設定からだけ取得する。Webから任意の設定本文は受け付けない。 */
final class InheritedVhost
{
    public static function render(string $original, array $spec, string $siteRoot, string $cert, string $key, ?string $sourceDomain = null): string
    {
        if (strlen($original) > 1024 * 1024 || str_contains($original, "\0")) throw new OpException('移行元の設定が大きすぎるか不正です');
        if (!preg_match_all('~^[\t ]*<VirtualHost\s+([^>]+)>\s*\R(.*?)^[\t ]*</VirtualHost>[^\S\r\n]*$~ims', $original, $blocks, PREG_SET_ORDER)) {
            throw new OpException('移行元には通常形式の VirtualHost が必要です');
        }
        if (preg_match_all('/<VirtualHost\b/i', $original) !== count($blocks)) throw new OpException('VirtualHost の構造を安全に解析できません');
        $docroot = rtrim($siteRoot . '/' . $spec['docroot'], '/');
        $has443 = false;
        foreach ($blocks as $block) {
            if (!preg_match('/^\s*ServerName\s+(\S+)[^\r\n]*$/mi', $block[2], $host)
                || strtolower(rtrim($host[1], '.')) !== strtolower($sourceDomain ?? $spec['domain'])) {
                throw new OpException('別サイトを含む設定や ServerName が一致しない設定は自動継承できません');
            }
            foreach (preg_split('/\s+/', trim($block[1])) as $address) {
                if (!preg_match('/:(80|443)$/', $address, $port)) throw new OpException('移行元のポートは80または443を指定してください');
                if ($port[1] === '443') $has443 = true;
            }
            if (preg_match('/:80\b/', $block[1]) && preg_match('/:443\b/', $block[1])) throw new OpException('80と443を同じ VirtualHost に指定した設定は自動継承できません');
        }
        if ($has443 && empty($spec['tls'])) throw new OpException('既存のHTTPS設定を継承するには「通信を暗号化して公開する」をオンにしてください');
        // 変更対象ディレクティブの継続行は、部分変更による設定破壊を避けて拒否する。
        if (preg_match('/^\s*(?:DocumentRoot|ServerName|SSLEngine|SSLCertificate\w*)\b[^\r\n]*\\\\\s*$/mi', $original)) {
            throw new OpException('公開フォルダ・証明書指定の継続行には自動対応できません');
        }
        $renderBlock = static function (string $address, string $body, bool $tls) use ($spec, $siteRoot, $docroot, $cert, $key, $sourceDomain): string {
            $map = [];
            if (preg_match('/^[\t ]*DocumentRoot\s+(?:"([^"]+)"|([^\s#]+))/mi', $body, $root)) {
                $oldDocroot = rtrim($root[1] !== '' ? $root[1] : $root[2], '/');
                if ($oldDocroot !== '' && $oldDocroot !== '/') $map[$oldDocroot] = $docroot;
                $suffix = '/' . trim($spec['docroot'], '/');
                if ($suffix !== '/' && str_ends_with($oldDocroot, $suffix)) {
                    $oldSite = substr($oldDocroot, 0, -strlen($suffix));
                    if ($oldSite !== '' && $oldSite !== '/') $map[$oldSite] = $siteRoot;
                }
            }
            // 標準配置で旧来の直置き・current・releases を使っていたパスも引き継ぐ。
            $base = dirname($siteRoot);
            if (preg_match_all('~' . preg_quote($base, '~') . '/releases/[0-9]{8}-[0-9]{6}(?=/|[\s"\x27<>]|$)~', $body, $releases)) {
                foreach ($releases[0] as $release) $map[$release] = $siteRoot;
            }
            $map[$base . '/shared'] = $base . '/shared';
            $map[$base . '/current'] = $siteRoot;
            $map[$base] = $siteRoot;
            // 長い一致を優先し、置換後のパスを再置換しない。
            uksort($map, static fn($a, $b) => strlen($b) <=> strlen($a));
            $pattern = '~(?:' . implode('|', array_map(static fn($p) => preg_quote($p, '~'), array_keys($map))) . ')(?=/|[\s"\x27<>]|$)~';
            $lines = [];
            foreach (preg_split('/\R/', $body) as $line) {
                $trim = trim($line);
                // 証明書とSSL有効化の指定は条件ブロック内も除去し、443で必ず共通設定を追加する。
                if (preg_match('/^(?:SSLEngine|SSLCertificateFile|SSLCertificateKeyFile|SSLCertificateChainFile)\s/i', $trim)) continue;
                if (preg_match('/^DocumentRoot\s/i', $trim)) continue;
                if (preg_match('/^ServerName\s/i', $trim)) { $lines[] = '    ServerName ' . $spec['domain']; continue; }
                // コメントや未知の指定はそのまま保持し、サイト配下パスだけを更新する。
                if ($trim !== '' && !str_starts_with($trim, '#')) {
                    $line = preg_replace_callback($pattern, static fn($m) => $map[$m[0]], $line);
                    if ($sourceDomain !== null && $sourceDomain !== $spec['domain']) {
                        $line = preg_replace('~(?<![a-zA-Z0-9_.-])' . preg_quote($sourceDomain, '~') . '(?![a-zA-Z0-9_.-])~i', $spec['domain'], $line);
                    }
                }
                $lines[] = $line;
            }
            $body = implode("\n", $lines);
            $addition = "    DocumentRoot {$docroot}\n";
            if ($tls) $addition .= "    SSLEngine on\n    SSLCertificateFile {$cert}\n    SSLCertificateKeyFile {$key}\n";
            return '<VirtualHost ' . $address . ">\n" . $body . "\n" . $addition . "</VirtualHost>";
        };
        $out = preg_replace_callback('~^[\t ]*<VirtualHost\s+([^>]+)>\s*\R(.*?)^[\t ]*</VirtualHost>[^\S\r\n]*$~ims',
            static fn($m) => $renderBlock($m[1], $m[2], (bool) preg_match('/:443\b/', $m[1])), $original);
        if (!$has443 && !empty($spec['tls'])) {
            // HTTPのみの設定には、同じ独自設定を使うHTTPSサイトを追加する。
            $out .= "\n" . $renderBlock('*:443', $blocks[0][2], true) . "\n";
        }
        return "# managed by pjas-ctrl -- do not edit by hand\n# inherited configuration; service: {$spec['name']}\n" . $out;
    }
}
