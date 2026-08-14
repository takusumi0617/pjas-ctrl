<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Spec;

/**
 * ファイルブラウザ。読み取りはサービス配下全体、書き込みは shared/ 配下のみ。
 * パスはすべて realpath で解決してサービス配下であることを確認する。
 */
final class Fs
{
    private const MAX_READ = 256 * 1024;

    public function __construct(private array $cfg, private Paths $paths)
    {
    }

    public function list(array $params): array
    {
        $name = Spec::name($params['service']['name'] ?? '');
        $rel  = Spec::relPath($params['path'] ?? '', 'path');
        $dir  = $this->paths->assertInside($name, $this->paths->base($name) . ($rel === '' ? '' : '/' . $rel));

        if (!is_dir($dir)) {
            throw new OpException('ディレクトリではありません');
        }

        $entries = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . '/' . $f;
            $type = is_link($full) ? 'link' : (is_dir($full) ? 'dir' : 'file');
            $st   = @lstat($full) ?: [];
            $entries[] = [
                'name'   => $f,
                'path'   => ltrim($rel . '/' . $f, '/'),
                'type'   => $type,
                'size'   => $type === 'file' ? (int) ($st['size'] ?? 0) : null,
                'mode'   => isset($st['mode']) ? substr(sprintf('%o', $st['mode']), -4) : '',
                'owner'  => $this->ownerOf($st),
                'mtime'  => isset($st['mtime']) ? date('Y-m-d H:i', (int) $st['mtime']) : '',
                'target' => $type === 'link' ? (string) readlink($full) : null,
                'writable' => str_starts_with(ltrim($rel . '/' . $f, '/'), 'shared/'),
            ];
        }
        usort($entries, static fn($a, $b) => [$a['type'] !== 'dir', $a['name']] <=> [$b['type'] !== 'dir', $b['name']]);

        return ['path' => $rel, 'entries' => $entries];
    }

    public function read(array $params): array
    {
        $name = Spec::name($params['service']['name'] ?? '');
        $rel  = Spec::relPath($params['path'] ?? '', 'path');
        $file = $this->paths->assertInside($name, $this->paths->base($name) . '/' . $rel);

        if (!is_file($file)) {
            throw new OpException('ファイルではありません');
        }
        if (filesize($file) > self::MAX_READ) {
            throw new OpException('ファイルが大きすぎます (256KB まで)');
        }
        $body = (string) file_get_contents($file);
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new OpException('テキストファイルではありません');
        }
        return ['path' => $rel, 'content' => $body];
    }

    /** shared/ 配下のテキストファイルのみ書き換えられる */
    public function write(array $params): array
    {
        $name = Spec::name($params['service']['name'] ?? '');
        $rel  = Spec::relPath($params['path'] ?? '', 'path');
        if (!str_starts_with($rel, 'shared/')) {
            throw new OpException('書き込めるのは shared/ 配下だけです');
        }
        $content = (string) ($params['content'] ?? '');
        if (strlen($content) > self::MAX_READ) {
            throw new OpException('内容が大きすぎます');
        }

        $file = $this->paths->base($name) . '/' . $rel;
        if (is_link($file)) {
            throw new OpException('シンボリックリンクは書き換えられません');
        }
        if (file_exists($file)) {
            $this->paths->assertInside($name, $file);
        } else {
            $this->paths->assertInside($name, dirname($file));
        }

        // 一時ファイル → rename で原子的に置き換える
        $tmp = $file . '.pjas-tmp';
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new OpException('書き込みに失敗しました');
        }
        chown($file, (string) $this->cfg['web_user']);
        chgrp($file, (string) $this->cfg['web_group']);
        chmod($file, 0640);

        return ['path' => $rel, 'bytes' => strlen($content)];
    }

    private function ownerOf(array $st): string
    {
        if (!isset($st['uid'])) {
            return '';
        }
        $u = function_exists('posix_getpwuid') ? posix_getpwuid((int) $st['uid']) : null;
        return $u['name'] ?? (string) $st['uid'];
    }
}
