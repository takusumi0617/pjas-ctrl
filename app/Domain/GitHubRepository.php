<?php
declare(strict_types=1);
namespace Pjas\Domain;

final class GitHubRepository
{
    public static function normalize(string $input): string
    {
        $input = trim($input);
        if (str_starts_with($input, 'https://github.com/')) $input = substr($input, strlen('https://github.com/'));
        $input = rtrim($input, '/');
        if (str_ends_with($input, '.git')) $input = substr($input, 0, -4);
        if (!preg_match('~\A([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9_][A-Za-z0-9_.-]{0,99})\z~D', $input, $m)
            || in_array($m[2], ['.', '..'], true)) {
            throw new \InvalidArgumentException('公開GitHubリポジトリを https://github.com/所有者/リポジトリ または 所有者/リポジトリ の形式で入力してください');
        }
        return 'https://github.com/' . $m[1] . '/' . $m[2] . '.git';
    }
    public static function ref(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') return 'HEAD';
        if (!preg_match('~\A[A-Za-z0-9_][A-Za-z0-9._/-]{0,99}\z~D', $ref)
            || str_contains($ref, '..') || str_contains($ref, '//') || str_ends_with($ref, '/')
            || str_ends_with($ref, '.') || str_ends_with($ref, '.lock')) {
            throw new \InvalidArgumentException('取得するブランチ名またはタグ名が不正です');
        }
        return $ref;
    }
}
