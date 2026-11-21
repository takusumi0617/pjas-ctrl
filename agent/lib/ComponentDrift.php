<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/** Compare only files recorded by component configuration approval. */
final class ComponentDrift
{
    public const LABELS = [
        'match' => '承認済み構成と一致',
        'missing' => 'ファイルが欠落',
        'changed' => '内容が変更されています',
        'invalid' => '通常ファイルではありません',
        'unreadable' => 'ファイルを読み取れません',
    ];

    public static function inspect(array $state): array
    {
        $rows = [];
        foreach ($state['files'] ?? [] as $path => $expected) {
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                $status = 'invalid';
            } elseif (!is_file($path)) {
                $status = 'missing';
            } else {
                $actual = @hash_file('sha256', $path);
                $status = $actual === false ? 'unreadable' : (hash_equals($expected, $actual) ? 'match' : 'changed');
            }
            $rows[] = ['path' => $path, 'status' => $status, 'detail' => self::LABELS[$status]];
        }
        return $rows;
    }
}
