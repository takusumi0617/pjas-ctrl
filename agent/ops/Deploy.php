<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\JobLog;
use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;

final class Deploy
{
    public function __construct(
        private array $cfg,
        private Paths $paths,
        private Vhost $vhost,
        private Health $health,
    ) {
    }

    /**
     * デプロイを開始する。実処理は子プロセスで走り、進捗は job ログに書く。
     * 呼び出し元 (web) には即座に job_id を返す。
     */
    public function start(array $params): array
    {
        $jobId = (int) ($params['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new OpException('job_id がありません');
        }
        $spec   = Spec::service($params['service'] ?? null);
        $source = $this->validateSource($spec, (array) ($params['source'] ?? []));
        $opts   = [
            'label'         => substr((string) ($params['label'] ?? ''), 0, 60),
            'auto_rollback' => !empty($params['auto_rollback']),
            'maintenance'   => !empty($params['maintenance']),
        ];

        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new OpException('デプロイプロセスを起動できません');
        }
        if ($pid === 0) {
            // 子: 実行して終了する
            posix_setsid();
            try {
                $this->run($jobId, $spec, $source, $opts);
            } catch (\Throwable $e) {
                error_log('[pjas-agentd] deploy job ' . $jobId . ': ' . $e->getMessage());
            }
            exit(0);
        }
        return ['job_id' => $jobId, 'pid' => $pid];
    }

    /** ロールバックも同じ経路 (current の張り替えとリロードだけ) */
    public function rollback(array $params): array
    {
        $jobId = (int) ($params['job_id'] ?? 0);
        $spec  = Spec::service($params['service'] ?? null);
        $tag   = Spec::tag($params['tag'] ?? '');
        $name  = $spec['name'];

        if (!is_dir($this->paths->release($name, $tag))) {
            throw new OpException("リリースディレクトリがありません: {$tag}");
        }

        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new OpException('ロールバックプロセスを起動できません');
        }
        if ($pid === 0) {
            posix_setsid();
            $log  = new JobLog($jobId, (string) $this->cfg['job_dir']);
            $prev = $this->paths->currentTag($name);
            try {
                $log->line("rollback {$name}: {$prev} → {$tag}");
                $this->switchCurrent($name, $tag, $log);
                $this->reloadRuntime($spec, $log);
                if ($this->health->poll($spec, fn($m) => $log->line($m))) {
                    $log->set('health', 'ok');
                } else {
                    $log->set('health', 'ng');
                }
                $log->set('release', ['tag' => $tag, 'state' => 'current', 'source' => 'rollback']);
                $log->finish('succeeded', "current → {$tag}");
            } catch (\Throwable $e) {
                $log->finish('failed', $e->getMessage());
            }
            exit(0);
        }
        return ['job_id' => $jobId, 'pid' => $pid];
    }

    // ---------------------------------------------------------------- 本体

    private function run(int $jobId, array $spec, array $source, array $opts): void
    {
        $name = $spec['name'];
        $tag  = date('Ymd-His');
        $log  = new JobLog($jobId, (string) $this->cfg['job_dir']);
        $prev = $this->paths->currentTag($name);
        $relDir = $this->paths->release($name, $tag);

        $log->line("deploy {$name} tag={$tag} source={$source['type']} 直前=" . ($prev ?? 'なし'));

        try {
            if ($opts['maintenance']) {
                touch($this->paths->shared($name) . '/maintenance.on');
                $log->line('メンテナンスモード ON (shared/maintenance.on)');
            }

            // [1-3] 取り込み・検疫・展開
            $meta = $source['type'] === 'git'
                ? $this->fetchGit($name, $source, $relDir, $log)
                : $this->extractArchive($name, $source, $relDir, $log);

            // [4] shared のリンク
            $this->linkShared($name, $spec, $relDir, $log);

            // [5] 権限の正規化
            $this->normalizePerms($relDir, $log);

            // [6] after_deploy フック (www-data として実行)
            foreach ($spec['hooks'] as $argv) {
                $log->line('hook: ' . implode(' ', $argv));
                $r = Proc::run($argv, $relDir, (int) $this->cfg['hook_timeout'], (string) $this->cfg['web_user'],
                    ['HOME' => $this->paths->shared($name), 'PATH' => '/usr/local/bin:/usr/bin:/bin']);
                $log->proc('  ' . $argv[0], $r);
                if ($r['code'] !== 0) {
                    throw new OpException('after_deploy フックが失敗しました: ' . implode(' ', $argv));
                }
            }

            // [7] 事前検証
            $this->lintPhp($relDir, $spec, $log);
            $docroot = rtrim($relDir . '/' . $spec['docroot'], '/');
            if (!is_dir($docroot)) {
                throw new OpException("DocumentRoot がリリース内にありません: {$spec['docroot']}");
            }
            $test = Proc::run([$this->cfg['apachectl'], 'configtest'], null, 30);
            $log->proc('apachectl configtest', $test);
            if ($test['code'] !== 0) {
                throw new OpException('apachectl configtest に失敗しました');
            }

            // [8] 切替 + リロード
            $this->switchCurrent($name, $tag, $log);
            $this->reloadRuntime($spec, $log);

            if ($opts['maintenance']) {
                @unlink($this->paths->shared($name) . '/maintenance.on');
                $log->line('メンテナンスモード OFF');
            }

            // [9] ヘルスチェック
            $healthy = $this->health->poll($spec, fn($m) => $log->line($m));
            $log->set('health', $healthy ? 'ok' : 'ng');

            if (!$healthy) {
                if ($opts['auto_rollback'] && $prev !== null) {
                    $log->line("ヘルスチェック失敗 → {$prev} へ自動ロールバック");
                    $this->switchCurrent($name, $prev, $log);
                    $this->reloadRuntime($spec, $log);
                    $log->set('release', ['tag' => $prev, 'state' => 'current', 'source' => 'auto-rollback']);
                    throw new OpException("ヘルスチェックに失敗したため {$prev} に戻しました");
                }
                throw new OpException('ヘルスチェックに失敗しました (自動ロールバックは無効)');
            }

            // [10] 掃除
            $pruned = $this->prune($name, $spec['keep_releases'], $log);

            $log->set('release', [
                'tag'    => $tag,
                'state'  => 'current',
                'label'  => $opts['label'],
                'source' => $meta['source'],
                'sha256' => $meta['sha256'],
                'size'   => $meta['size'],
                'pruned' => $pruned,
            ]);
            $log->finish('succeeded', "current → {$tag}");
        } catch (\Throwable $e) {
            @unlink($this->paths->shared($name) . '/maintenance.on');
            // 切替前に落ちた場合は展開済みディレクトリを片付ける
            if ($this->paths->currentTag($name) !== $tag && is_dir($relDir)) {
                Proc::run(['/bin/rm', '-rf', '--', $relDir], null, 120);
                $log->line("失敗したリリース {$tag} を削除しました");
            }
            $log->finish('failed', $e->getMessage());
        } finally {
            if (($source['type'] ?? '') === 'upload' && isset($source['path'])) {
                @unlink($source['path']);
            }
        }
    }

    // ------------------------------------------------------------ 取り込み

    private function validateSource(array $spec, array $src): array
    {
        $type = (string) ($src['type'] ?? '');
        if ($type === 'git') {
            $repos = (array) $this->cfg['git_repos'];
            if (empty($repos[$spec['name']])) {
                throw new OpException("このサービスには git リポジトリが登録されていません: {$spec['name']}");
            }
            $ref = (string) ($src['ref'] ?? '');
            if (!preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $ref)) {
                throw new OpException('git ref が不正です');
            }
            return ['type' => 'git', 'ref' => $ref, 'repo' => (string) $repos[$spec['name']]];
        }

        // アップロードは upload_dir 直下のファイルのみ受け付ける
        $path = (string) ($src['path'] ?? '');
        $dir  = realpath((string) $this->cfg['upload_dir']);
        $real = realpath($path);
        if ($dir === false || $real === false || dirname($real) !== $dir) {
            throw new OpException('アーカイブの場所が不正です');
        }
        if (!preg_match('/\.(zip|tar\.gz|tgz)$/', $real)) {
            throw new OpException('対応していないアーカイブ形式です');
        }
        $sha = (string) ($src['sha256'] ?? '');
        if ($sha !== '' && !hash_equals($sha, hash_file('sha256', $real))) {
            throw new OpException('アーカイブの SHA-256 が一致しません');
        }
        return ['type' => 'upload', 'path' => $real, 'sha256' => hash_file('sha256', $real),
                'name' => basename((string) ($src['name'] ?? basename($real)))];
    }

    private function extractArchive(string $name, array $src, string $relDir, JobLog $log): array
    {
        $tmp = $this->paths->releases($name) . '/.tmp-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0755, true);

        try {
            if (str_ends_with($src['path'], '.zip')) {
                // -o: 上書き。unzip はエントリ名の先頭 / と .. を取り除く
                Proc::must(['/usr/bin/unzip', '-q', '-o', $src['path'], '-d', $tmp], null, 300);
            } else {
                Proc::must(['/bin/tar', '-xzf', $src['path'], '-C', $tmp, '--no-same-owner', '--no-same-permissions'], null, 300);
            }
            $log->line('展開しました: ' . basename($src['path']));

            $this->assertNoEscape($tmp, $log);

            // 単一トップディレクトリなら、その中身をリリース直下に置く
            $entries = array_values(array_diff(scandir($tmp) ?: [], ['.', '..']));
            $root    = (count($entries) === 1 && is_dir($tmp . '/' . $entries[0])) ? $tmp . '/' . $entries[0] : $tmp;
            if ($root !== $tmp) {
                $log->line("単一トップディレクトリ {$entries[0]}/ をリリース直下として扱います");
            }

            if (!rename($root, $relDir)) {
                throw new OpException('リリースディレクトリへの移動に失敗しました');
            }
            if (is_dir($tmp)) {
                Proc::run(['/bin/rm', '-rf', '--', $tmp], null, 60);
            }
        } catch (\Throwable $e) {
            Proc::run(['/bin/rm', '-rf', '--', $tmp], null, 60);
            throw $e;
        }

        return [
            'source' => 'upload:' . ($src['name'] ?? basename($src['path'])),
            'sha256' => $src['sha256'],
            'size'   => (int) filesize($src['path']),
        ];
    }

    private function fetchGit(string $name, array $src, string $relDir, JobLog $log): array
    {
        Proc::must(['/usr/bin/git', 'clone', '--depth', '1', '--branch', $src['ref'], '--', $src['repo'], $relDir], null, 600);
        $sha = trim(Proc::must(['/usr/bin/git', 'rev-parse', 'HEAD'], $relDir, 30));
        Proc::run(['/bin/rm', '-rf', '--', $relDir . '/.git'], null, 120);
        $log->line("git {$src['ref']} @ " . substr($sha, 0, 8) . ' を取得しました');

        return ['source' => 'git:' . $src['ref'] . '@' . substr($sha, 0, 8), 'sha256' => $sha, 'size' => 0];
    }

    /** 展開結果に外を指すシンボリックリンクが無いことを確認する */
    private function assertNoEscape(string $tmp, JobLog $log): void
    {
        $base = realpath($tmp);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $links = 0;
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isLink()) {
                $target = realpath($f->getPathname());
                if ($target === false || !str_starts_with($target . '/', $base . '/')) {
                    throw new OpException('アーカイブ外を指すシンボリックリンクが含まれています: ' . $f->getFilename());
                }
                $links++;
            }
        }
        if ($links) {
            $log->line("検疫: サービス内で完結するシンボリックリンク {$links} 件を確認しました");
        }
    }

    // ---------------------------------------------------------------- 配置

    private function linkShared(string $name, array $spec, string $relDir, JobLog $log): void
    {
        foreach ($spec['shared'] as $p) {
            $sharedPath  = $this->paths->shared($name) . '/' . $p;
            $releasePath = $relDir . '/' . $p;

            if (!file_exists($sharedPath)) {
                @mkdir(dirname($sharedPath), 0755, true);
                if (is_file($releasePath)) {
                    copy($releasePath, $sharedPath);     // 初回はリリース同梱を引き継ぐ
                } elseif (str_contains(basename($p), '.')) {
                    touch($sharedPath);
                } else {
                    mkdir($sharedPath, 0775, true);
                }
                $log->line("shared を初期化しました: {$p}");
            }
            if (file_exists($releasePath) || is_link($releasePath)) {
                Proc::run(['/bin/rm', '-rf', '--', $releasePath], null, 60);
            }
            @mkdir(dirname($releasePath), 0755, true);
            if (!symlink($sharedPath, $releasePath)) {
                throw new OpException("shared のリンクに失敗しました: {$p}");
            }
        }
        if ($spec['shared']) {
            $log->line('shared をリンクしました: ' . implode(', ', $spec['shared']));
        }
    }

    private function normalizePerms(string $relDir, JobLog $log): void
    {
        $user = (string) $this->cfg['web_user'];
        $grp  = (string) $this->cfg['web_group'];
        Proc::must(['/bin/chown', '-Rh', "{$user}:{$grp}", $relDir], null, 300);
        // setuid/setgid とその他書き込み権を落とし、ディレクトリ 755 / ファイル 644 に揃える
        Proc::must(['/bin/chmod', '-R', 'a-st,o-w', $relDir], null, 300);
        Proc::run(['/usr/bin/find', $relDir, '-type', 'd', '-exec', '/bin/chmod', '755', '{}', '+'], null, 300);
        Proc::run(['/usr/bin/find', $relDir, '-type', 'f', '-exec', '/bin/chmod', '644', '{}', '+'], null, 300);
        $log->line("所有者を {$user}:{$grp}、権限を dir 755 / file 644 に正規化しました");
    }

    private function lintPhp(string $relDir, array $spec, JobLog $log): void
    {
        if ($spec['runtime'] === 'static') {
            return;
        }
        $php = '/usr/bin/php' . str_replace(['php', '-fpm'], '', $spec['runtime']);
        if (!is_executable($php)) {
            $php = '/usr/bin/php';
        }
        $r = Proc::run(['/usr/bin/find', $relDir, '-name', '*.php', '-type', 'f',
                        '-not', '-path', '*/vendor/*', '-not', '-path', '*/node_modules/*'], null, 120);
        $files = array_values(array_filter(explode("\n", trim($r['out']))));
        $bad   = [];
        foreach ($files as $f) {
            $l = Proc::run([$php, '-l', '-n', $f], null, 20);
            if ($l['code'] !== 0) {
                $bad[] = trim($l['out'] ?: $l['err']);
                if (count($bad) >= 5) {
                    break;
                }
            }
        }
        if ($bad) {
            throw new OpException("PHP 構文エラー:\n" . implode("\n", $bad));
        }
        $log->line('php -l: ' . count($files) . ' ファイル OK');
    }

    /** current を原子的に張り替える (ln -sfn + mv -T) */
    private function switchCurrent(string $name, string $tag, JobLog $log): void
    {
        $target = $this->paths->release($name, $tag);
        if (!is_dir($target)) {
            throw new OpException("リリースがありません: {$tag}");
        }
        $tmpLink = $this->paths->base($name) . '/.current-' . bin2hex(random_bytes(4));
        if (!symlink($target, $tmpLink)) {
            throw new OpException('シンボリックリンクを作成できません');
        }
        $r = Proc::run(['/bin/mv', '-Tf', $tmpLink, $this->paths->current($name)], null, 30);
        if ($r['code'] !== 0) {
            @unlink($tmpLink);
            throw new OpException('current の張り替えに失敗しました: ' . trim($r['err']));
        }
        @lchown($this->paths->current($name), (string) $this->cfg['web_user']);
        @lchgrp($this->paths->current($name), (string) $this->cfg['web_group']);
        $log->line("current → releases/{$tag}");
    }

    private function reloadRuntime(array $spec, JobLog $log): void
    {
        if ($spec['runtime'] !== 'static') {
            $r = Proc::run([$this->cfg['systemctl'], 'reload', $spec['runtime']], null, 60);
            $log->proc("systemctl reload {$spec['runtime']}", $r);
        }
        $r = Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
        $log->proc('apachectl graceful', $r);
    }

    /** @return array<int,string> 削除したタグ */
    private function prune(string $name, int $keep, JobLog $log): array
    {
        $tags    = $this->paths->releaseTags($name);
        $current = $this->paths->currentTag($name);
        $pruned  = [];
        foreach (array_slice($tags, $keep) as $old) {
            if ($old === $current) {
                continue;
            }
            Proc::run(['/bin/rm', '-rf', '--', $this->paths->release($name, $old)], null, 120);
            $pruned[] = $old;
        }
        if ($pruned) {
            $log->line('古いリリースを削除しました: ' . implode(', ', $pruned));
        }
        return $pruned;
    }
}
