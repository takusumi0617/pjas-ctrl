<?php
declare(strict_types=1);
namespace Pjas\Domain;

final class RegistrationSource
{
    public static function read(array $in): array
    {
        $type = (string) ($in['source'] ?? (!empty($in['github_repo']) ? 'git' : 'upload'));
        if (!in_array($type, ['git','upload'], true)) throw new \InvalidArgumentException('ファイルの取得元を選んでください');
        if ($type === 'upload') return ['type'=>'upload','repo'=>'','ref'=>'HEAD','manifest'=>false,'token'=>''];
        $repo = GitHubRepository::normalize((string) ($in['github_repo'] ?? ''));
        $manifest = !empty($in['read_manifest']);
        return ['type'=>'git','repo'=>$repo,'ref'=>GitHubRepository::ref((string)($in['github_ref'] ?? '')),
            'manifest'=>$manifest,'token'=>$manifest ? (string)($in['manifest_token'] ?? '') : ''];
    }
}
