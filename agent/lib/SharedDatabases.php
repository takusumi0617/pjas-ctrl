<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** ファイル型DBの実体をsharedに置き、各リリースから同じ実体を使う。 */
final class SharedDatabases
{
    public function __construct(private array $cfg, private Paths $paths) {}

    public static function discover(string $root): array
    {
        if (!is_dir($root)) return [];
        $result = []; $seen = 0;
        $walk = static function (string $dir, string $prefix) use (&$walk, &$result, &$seen): void {
            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$seen > 50000) throw new OpException('DBファイルの検出対象が多すぎます。引き継ぐフォルダを明示してください');
                if ($entry->isLink()) continue;
                $path = $prefix . $entry->getFilename();
                if ($entry->isDir()) {
                    if (!in_array($entry->getFilename(), ['.git','node_modules','vendor','.pjas-db-backup'], true)) $walk($entry->getPathname(), $path . '/');
                } elseif ($entry->isFile()) {
                    $database = (bool) preg_match('/\.(?:db|sqlite|sqlite3)$/i', $path);
                    if (!$database && !preg_match('/-(?:wal|shm|journal)$/', $path)) {
                        $fp = @fopen($entry->getPathname(), 'rb');
                        if ($fp) { $database = fread($fp, 16) === "SQLite format 3\0"; fclose($fp); }
                    }
                    if ($database) $result[] = Spec::relPath($path, 'データベース');
                }
            }
        };
        $walk($root, '');
        sort($result);
        return $result;
    }

    private function inside(string $root, string $path): void
    {
        clearstatcache(true);
        $base = realpath($root);
        $probe = $path;
        while (!file_exists($probe) && !is_link($probe)) $probe = dirname($probe);
        $real = realpath($probe);
        if ($base === false || $real === false || !str_starts_with($real . '/', $base . '/')) throw new OpException('DBファイルの保存先が管理フォルダの外を指しています');
    }

    private function parents(string $root, string $file): void
    {
        $this->inside($root, dirname($file));
        $missing = [];
        $dir = dirname($file);
        while (!is_dir($dir)) { $missing[] = $dir; $dir = dirname($dir); }
        foreach (array_reverse($missing) as $part) {
            if (!mkdir($part, 0775)) throw new OpException('DBファイルの保存先を作成できません');
            $this->own($part, 0775);
        }
        $this->inside($root, dirname($file));
    }

    private function own(string $file, int $mode): void
    {
        if (!chown($file, $this->cfg['web_user']) || !chgrp($file, $this->cfg['web_group']) || !chmod($file, $mode)) throw new OpException('DBファイルの所有者・権限を設定できません');
    }

    public function preparePath(string $name, string $relative): string
    {
        $shared = $this->paths->shared($name);
        if (is_link($shared)) throw new OpException('共有データの保存先が不正です');
        $target = $shared . '/' . Spec::relPath($relative, '共有データ');
        $this->parents($shared, $target);
        if (is_link($target)) throw new OpException('共有データの保存先がシンボリックリンクです');
        return $target;
    }

    public function link(string $name, string $release, array $files, ?string $current, JobLog $log): void
    {
        $shared = $this->paths->shared($name);
        if (is_link($shared)) throw new OpException('共有データの保存先が不正です');
        foreach (array_unique($files) as $relative) {
            $relative = Spec::relPath($relative, 'データベース');
            $target = $shared . '/' . $relative;
            $this->parents($shared, $target);
            if (is_link($target) || (file_exists($target) && !is_file($target))) throw new OpException('DBファイルの保存先が不正です: ' . $relative);
            if (!file_exists($target)) {
                $source = null;
                // 公開中のデータを優先し、新しいアーカイブ内の初期データで上書きしない。
                foreach (array_filter([$current, $release]) as $root) {
                    $candidate = $root . '/' . $relative;
                    if (is_file($candidate) && !is_link($candidate)) { $this->inside($root, $candidate); $source = $candidate; break; }
                }
                if ($source === null) continue;
                foreach (['','-wal','-shm','-journal'] as $suffix) {
                    if (!file_exists($source . $suffix) && !is_link($source . $suffix)) continue;
                    if (is_link($source . $suffix) || !is_file($source . $suffix) || file_exists($target . $suffix) || is_link($target . $suffix)) throw new OpException('DB付属ファイルが競合しています: ' . $relative . $suffix);
                }
                $moved = [];
                try {
                    foreach (['','-wal','-shm','-journal'] as $suffix) {
                        if (!file_exists($source . $suffix)) continue;
                        if (!rename($source . $suffix, $target . $suffix)) throw new OpException('DBファイルを引き継げません: ' . $relative . $suffix);
                        $moved[] = $suffix;
                        $this->own($target . $suffix, 0660);
                    }
                    // 自動復帰する現在のリリースからも同じ実体を使用する。
                    if ($current !== null && str_starts_with($source, $current . '/') && !symlink($target, $source)) throw new OpException('公開中のDBファイルを共有先につなげません');
                } catch (\Throwable $e) {
                    if (is_link($source) && readlink($source) === $target) @unlink($source);
                    foreach (array_reverse($moved) as $suffix) {
                        if (!file_exists($source . $suffix) && !is_link($source . $suffix)) @rename($target . $suffix, $source . $suffix);
                    }
                    throw $e;
                }
                $log->line('DBファイルを共有データへ引き継ぎました: ' . $relative);
            }
            $link = $release . '/' . $relative;
            clearstatcache(true);
            if (realpath($link) === realpath($target)) continue;
            $this->inside($release, dirname($link));
            if (is_dir($link) && !is_link($link)) throw new OpException('DBと同名のフォルダがあります: ' . $relative);
            if ((file_exists($link) || is_link($link)) && !unlink($link)) throw new OpException('DBファイルのリンクを更新できません');
            $this->parents($release, $link);
            if (!symlink($target, $link)) throw new OpException('DBファイルを共有先につなげません');
            $log->line('DBファイルを継続使用します: ' . $relative);
        }
    }
}
