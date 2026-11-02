<?php
declare(strict_types=1);

namespace Pjas\Agentd;

final class Paths
{
    public function __construct(private string $wwwRoot)
    {
    }

    public function base(string $name): string     { return $this->wwwRoot . '/' . $name; }
    public function releases(string $name): string { return $this->base($name) . '/releases'; }
    public function shared(string $name): string   { return $this->base($name) . '/shared'; }
    public function current(string $name): string  { return $this->base($name) . '/current'; }

    public function release(string $name, string $tag): string
    {
        return $this->releases($name) . '/' . $tag;
    }

    /** current が指しているリリースタグ */
    public function currentTag(string $name): ?string
    {
        clearstatcache(true);
        $link = $this->current($name);
        if (!is_link($link)) {
            return null;
        }
        $tag = basename((string) readlink($link));
        return preg_match('/^\d{8}-\d{6}$/', $tag) ? $tag : null;
    }

    /** @return array<int,string> 新しい順のリリースタグ */
    public function releaseTags(string $name): array
    {
        $dir = $this->releases($name);
        if (!is_dir($dir)) {
            return [];
        }
        $tags = array_values(array_filter(
            scandir($dir) ?: [],
            static fn($f) => (bool) preg_match('/^\d{8}-\d{6}$/', $f)
        ));
        rsort($tags);
        return $tags;
    }

    /** 解決後のパスがサービス配下にあることを保証する */
    public function assertInside(string $name, string $path): string
    {
        clearstatcache(true);
        $base = realpath($this->base($name));
        $real = realpath($path);
        if ($base === false || $real === false || !str_starts_with($real . '/', $base . '/')) {
            throw new OpException('サービスディレクトリの外を指しています: ' . $path);
        }
        return $real;
    }
}
