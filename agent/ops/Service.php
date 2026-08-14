<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
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
