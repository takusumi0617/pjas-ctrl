<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;

final class Service
{
    public function __construct(private array $cfg, private Paths $paths)
    {
    }

    /** /var/www/<name>/{releases,shared} を作る。既存ディレクトリは壊さない。 */
    public function create(array $params): array
    {
        $s      = (array) ($params['service'] ?? []);
        $name   = Spec::name($s['name'] ?? '');
        $shared = [];
        foreach ((array) ($s['shared'] ?? []) as $p) {
            $p = Spec::relPath($p, 'shared');
            if ($p !== '') {
                $shared[] = $p;
            }
        }

        $base = $this->paths->base($name);
        if (is_link($base)) {
            throw new OpException("{$base} はシンボリックリンクです");
        }

        foreach ([$base, $this->paths->releases($name), $this->paths->shared($name)] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new OpException("ディレクトリを作成できません: {$dir}");
            }
            $this->own($dir, 0755);
        }

        // shared の雛形を用意する (.env のようなファイルは空で作る)
        foreach ($shared as $p) {
            $target = $this->paths->shared($name) . '/' . $p;
            if (file_exists($target)) {
                continue;
            }
            if (str_contains(basename($p), '.')) {
                @mkdir(dirname($target), 0755, true);
                touch($target);
                $this->own($target, 0640);
            } else {
                mkdir($target, 0775, true);
                $this->own($target, 0775);
            }
        }

        return ['base' => $base, 'created' => true];
    }

    /**
     * www_root 配下を調べて、まだ管理下に無いディレクトリを候補として返す。
     * 導入時にサイトをまとめて登録するための一覧で、ファイルは一切変更しない。
     *
     * 各候補には推測値 (公開フォルダ / 実行環境 / 公開アドレス) を添える。
     * 推測なので、そのまま登録させるのではなく画面で確認・修正できるようにすること。
     */
    public function scan(array $params): array
    {
        $known = [];
        foreach ((array) ($params['known'] ?? []) as $n) {
            if (is_string($n)) {
                $known[$n] = true;
            }
        }

        $root  = rtrim((string) $this->cfg['www_root'], '/');
        $found = [];

        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $name) {
            $base = $root . '/' . $name;
            if (!is_dir($base) || is_link($base) || isset($known[$name])) {
                continue;
            }
            $plan = Deploy::adoptPlan($base);
            $found[] = [
                'name'       => $name,
                'path'       => $base,
                'eligible'   => (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $name),
                'files'      => count($plan['entries']),          // 引き継げるファイル / フォルダの数
                'managed'    => is_dir($base . '/releases') && is_dir($base . '/shared'),
                'has_current'=> file_exists($base . '/current'),
                'docroot'    => $this->guessDocroot($base, $plan),
                'runtime'    => $this->guessRuntime($base, $plan),
                'domain'     => $this->vhostDomain($base),
            ];
        }

        usort($found, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return ['root' => $root, 'services' => $found];
    }

    /** 公開フォルダの推測。よくある名前が実在すればそれを使う。 */
    private function guessDocroot(string $base, array $plan): string
    {
        $dir = $plan['flatten'] !== null ? $base . '/' . $plan['flatten'] : $base;
        foreach (Deploy::DOCROOT_NAMES as $cand) {
            if (is_dir($dir . '/' . $cand)) {
                return $cand;
            }
        }
        return '';
    }

    /** 実行環境の推測。PHP ファイルが 1 つでもあれば PHP とみなす。 */
    private function guessRuntime(string $base, array $plan): string
    {
        $dir = $plan['flatten'] !== null ? $base . '/' . $plan['flatten'] : $base;
        $r = Proc::run(['/usr/bin/find', $dir, '-maxdepth', '3', '-name', '*.php', '-type', 'f',
                        '-not', '-path', '*/vendor/*', '-print', '-quit'], null, 20);
        return trim($r['out']) !== '' ? 'php8.3-fpm' : 'static';
    }

    /** 既存の Apache 設定から、このディレクトリを公開しているドメインを拾う。 */
    private function vhostDomain(string $base): string
    {
        $dir = rtrim((string) ($this->cfg['apache_sites'] ?? ''), '/');
        if ($dir === '' || !is_dir($dir)) {
            return '';
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $conf) {
            if (!str_ends_with($conf, '.conf')) {
                continue;
            }
            $body = (string) @file_get_contents($dir . '/' . $conf);
            if ($body === '' || str_starts_with($body, Vhost::MANAGED_HEADER)) {
                continue;
            }
            if (!preg_match('/^\s*DocumentRoot\s+"?(\S+?)"?\s*$/mi', $body, $d)) {
                continue;
            }
            $docroot = rtrim($d[1], '/');
            if ($docroot !== $base && !str_starts_with($docroot . '/', $base . '/')) {
                continue;
            }
            if (preg_match('/^\s*ServerName\s+(\S+)/mi', $body, $m)) {
                return strtolower(rtrim($m[1], '.'));
            }
        }
        return '';
    }

    /** サービスを削除する (releases と shared を残したままアーカイブに退避) */
    public function destroy(array $params): array
    {
        $name = Spec::name(($params['service']['name'] ?? ''));
        $base = $this->paths->base($name);
        if (!is_dir($base)) {
            throw new OpException("サービスディレクトリがありません: {$base}");
        }
        $dest = $base . '.removed-' . date('Ymd-His');
        if (!rename($base, $dest)) {
            throw new OpException("退避に失敗しました: {$base}");
        }
        return ['archived_to' => $dest];
    }

    private function own(string $path, int $mode): void
    {
        @chown($path, (string) $this->cfg['web_user']);
        @chgrp($path, (string) $this->cfg['web_group']);
        @chmod($path, $mode);
    }
}
