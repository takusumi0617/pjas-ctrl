<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once dirname(__DIR__, 2) . '/shared/SiteErrors.php';

/** サーバー内だけのroot所有Git履歴。外部リポジトリ・フックは使わない。 */
final class ApacheHistory
{
    public function __construct(private array $cfg) {}

    private static function git(array $args, ?string $cwd = null, array $accepted = [0]): array
    {
        $env = ['PATH'=>'/usr/bin:/bin', 'LC_ALL'=>'C', 'GIT_CONFIG_NOSYSTEM'=>'1', 'GIT_CONFIG_GLOBAL'=>'/dev/null',
            'GIT_TERMINAL_PROMPT'=>'0', 'GIT_ATTR_NOSYSTEM'=>'1', 'GIT_CEILING_DIRECTORIES'=>$cwd === null ? '/' : dirname($cwd)];
        $argv = array_merge(['/usr/bin/git', '--no-pager', '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.attributesFile=/dev/null', '-c', 'commit.gpgSign=false',
            '-c', 'user.name=pjas-ctrl', '-c', 'user.email=pjas-ctrl@localhost'], $args);
        $result = Proc::run($argv, $cwd, 30, null, $env);
        if (!in_array($result['code'], $accepted, true)) throw new OpException('Apache設定のGit処理に失敗しました: ' . trim($result['err'] ?: $result['out']));
        return $result;
    }

    /** リポジトリを作らず、Git本来の行比較を使用する。 */
    public static function diff(string $old, string $new): array
    {
        $dir = sys_get_temp_dir() . '/pjas-apache-diff-' . bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) throw new OpException('差分の作業フォルダを作成できません');
        try {
            foreach (['before.conf'=>$old, 'after.conf'=>$new] as $name=>$body) {
                if (file_put_contents($dir . '/' . $name, $body) === false) throw new OpException('差分の作業ファイルを作成できません');
            }
            $result = self::git(['diff', '--no-index', '--no-ext-diff', '--no-textconv', '--no-color', '--text',
                '--diff-algorithm=myers', '--unified=3', '--', 'before.conf', 'after.conf'], $dir, [0,1]);
            $rows = [];
            $inHunk = false;
            foreach (explode("\n", rtrim($result['out'], "\n")) as $line) {
                if (str_starts_with($line, '@@')) { $inHunk = true; $rows[] = ['@', $line]; continue; }
                if (!$inHunk || $line === '') continue;
                if (in_array($line[0], [' ', '+', '-'], true)) $rows[] = [$line[0], substr($line, 1)];
                elseif ($line[0] === '\\') $rows[] = ['@', '末尾の改行なし'];
            }
            return $rows;
        } finally {
            @unlink($dir . '/before.conf'); @unlink($dir . '/after.conf'); @rmdir($dir);
        }
    }

    public static function assertErrorOnly(string $old, string $new): void
    {
        if (self::diff(\Pjas\Shared\SiteErrors::withoutGenerated($old), \Pjas\Shared\SiteErrors::withoutGenerated($new)) !== []) {
            throw new OpException('共通エラーページ以外の変更を検出したため、反映を止めました');
        }
    }

    private function path(string $name): string
    {
        return rtrim($this->cfg['apache_sites'], '/') . '/.pjas-history/' . Spec::name($name);
    }

    private static function privateDir(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) throw new OpException('Git履歴の保存先が不正です');
        $stat = stat($dir);
        if ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0077)) throw new OpException('Git履歴はroot所有・管理者だけが読み書きできる状態にしてください');
    }

    /** 保存先の親も、Webやサイトのユーザーによって置換できないことを検証。 */
    private function repository(string $name, bool $create): ?string
    {
        $parent = rtrim($this->cfg['apache_sites'], '/');
        $stat = stat($parent);
        if (is_link($parent) || !$stat || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022)) throw new OpException('Apache設定フォルダはroot所有で、他のユーザーが書き込めない状態にしてください');
        $dir = $this->path($name);
        foreach ([dirname($dir), $dir] as $part) {
            if (!file_exists($part) && !is_link($part)) {
                if (!$create) return null;
                if (!mkdir($part, 0700)) throw new OpException('Git履歴の保存先を作成できません');
            }
            self::privateDir($part);
        }
        if (is_link($dir . '/.git') || (file_exists($dir . '/.git') && !is_dir($dir . '/.git'))) throw new OpException('Git履歴の保存先が不正です');
        if (!is_dir($dir . '/.git')) {
            if (!$create) throw new OpException('Git履歴が破損しています');
            self::git(['init', '--initial-branch=main', '--template=', '.'], $dir);
        }
        if (is_link($dir . '/.git')) throw new OpException('Git履歴の保存先が不正です');
        return $dir;
    }

    /** confを更新する前に必ず保存。手作業での変更もその時点の内容を取り込む。 */
    public function save(string $name, ?string $body, string $phase, string $actor): string
    {
        $dir = $this->repository($name, true);
        $existing = is_file($dir . '/apache.conf') ? file_get_contents($dir . '/apache.conf') : null;
        $state = is_file($dir . '/state.json') ? json_decode((string)file_get_contents($dir . '/state.json'), true) : null;
        if ($state !== null && $existing === ($body ?? '') && $state['present'] === ($body !== null)) {
            $head = self::git(['rev-parse', '--verify', 'HEAD'], $dir, [0,128]);
            if ($head['code'] === 0 && self::git(['diff', '--quiet', 'HEAD', '--', 'apache.conf', 'state.json'], $dir, [0,1])['code'] === 0) {
                return trim($head['out']);
            }
        }
        foreach (['apache.conf'=>$body ?? '', 'state.json'=>json_encode(['service'=>$name, 'present'=>$body !== null,
            'phase'=>$phase, 'actor'=>substr($actor,0,120), 'time'=>gmdate('c')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)] as $file=>$content) {
            if (is_link($dir . '/' . $file)) throw new OpException('Git履歴の保存先が不正です');
            if (file_put_contents($dir . '/' . $file, $content, LOCK_EX) === false) throw new OpException('Git履歴を保存できません');
            chmod($dir . '/' . $file, 0600);
        }
        self::git(['add', '--', 'apache.conf', 'state.json'], $dir);
        self::git(['commit', '--no-gpg-sign', '-m', $phase, '--only', '--', 'apache.conf', 'state.json'], $dir);
        return trim(self::git(['rev-parse', '--verify', 'HEAD'], $dir)['out']);
    }

    public function listing(string $name): array
    {
        $dir = $this->repository($name, false);
        if ($dir === null) return [];
        $head = self::git(['rev-parse', '--verify', 'HEAD'], $dir, [0,128]);
        if ($head['code'] !== 0) return [];
        $log = self::git(['log', '-10', '--format=%H%x09%cI%x09%s', 'HEAD', '--'], $dir)['out'];
        $rows = [];
        foreach (explode("\n", trim($log)) as $line) {
            [$revision,$time,$phase] = explode("\t", $line, 3);
            $rows[] = ['revision'=>$revision,'time'=>$time,'phase'=>$phase];
        }
        return $rows;
    }
}
