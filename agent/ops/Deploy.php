<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\JobLog;
use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;
use Pjas\Agentd\GitRepositories;
use Pjas\Agentd\ManifestImport;
use Pjas\Agentd\SharedDatabases;
require_once dirname(__DIR__) . '/lib/SharedDatabases.php';

final class Deploy
{
    /** よくある公開フォルダ名。引き継ぎで畳まず、公開フォルダの推測にも使う */
    public const DOCROOT_NAMES = ['public', 'web', 'htdocs', 'public_html', 'dist'];

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
            'apply_vhost'   => !empty($params['apply_vhost']) || !empty($source['manifest_plan']) || (!empty($source['public_github']) && ($this->paths->currentTag($spec['name']) === null || !is_file($this->vhost->confPath($spec['name'])))),
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

    public function releases(array $params): array
    {
        $name = Spec::name($params['service']['name'] ?? '');
        $tags = [];
        foreach ($this->paths->releaseTags($name) as $tag) {
            $path = $this->paths->release($name, $tag);
            if (is_dir($path) && !is_link($path)) { $this->paths->assertInside($name, $path); $tags[] = $tag; }
        }
        return ['current'=>$this->paths->currentTag($name), 'tags'=>$tags];
    }

    private function lockSite(string $name)
    {
        $path = rtrim($this->cfg['apache_sites'], '/') . '/.pjas-deploy-' . Spec::name($name) . '.lock';
        if (is_link($path)) throw new OpException('公開作業のロックが不正です');
        $lock = fopen($path, 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new OpException('このサイトの公開作業をロックできません');
        return $lock;
    }

    public function rollback(array $params): array
    {
        $jobId = (int) ($params['job_id'] ?? 0);
        if ($jobId <= 0) throw new OpException('job_id がありません');
        $spec = Spec::service($params['service'] ?? null);
        $tag = Spec::tag($params['tag'] ?? '');
        $available = $this->releases(['service'=>$spec]);
        if (!in_array($tag, $available['tags'], true)) throw new OpException('戻せるバージョンがサーバーに残っていません');
        if ($available['current'] === $tag) throw new OpException('そのバージョンはすでに公開中です');
        $expected = (string) ($params['expected_current'] ?? $available['current'] ?? '');
        if ($expected !== '') Spec::tag($expected);
        $pid = pcntl_fork();
        if ($pid < 0) throw new OpException('ロールバックプロセスを起動できません');
        if ($pid === 0) {
            posix_setsid();
            $this->runRollback($jobId, $spec, $tag, $expected);
            exit(0);
        }
        return ['job_id'=>$jobId, 'pid'=>$pid];
    }

    private function runRollback(int $jobId, array $spec, string $tag, string $expected): void
    {
        $log = new JobLog($jobId, (string) $this->cfg['job_dir']);
        $name = $spec['name']; $prev = null; $lock = null;
        try {
            $lock = $this->lockSite($name);
            $prev = $this->paths->currentTag($name);
            if (($prev ?? '') !== $expected) throw new OpException('公開中のバージョンが変わりました。画面を開き直して戻す内容を確認してください');
            if (!in_array($tag, $this->releases(['service'=>$spec])['tags'], true)) throw new OpException('戻せるバージョンがサーバーに残っていません');
            $log->line("rollback {$name}: {$prev} → {$tag}（共有データは最新の状態を維持）");
            $this->linkShared($name, $spec, $this->paths->release($name, $tag), $log);
            $this->switchCurrent($name, $tag, $log);
            $this->reloadRuntime($spec, $log);
            if (!$this->health->poll($spec, fn($m)=>$log->line($m))) throw new OpException('戻したバージョンの動作確認に失敗したため、元のバージョンへ復帰します');
            $log->set('health','ok');
            $log->set('release',['tag'=>$tag,'state'=>'current','source'=>'rollback']);
            $log->finish('succeeded', "current → {$tag}");
        } catch (\Throwable $e) {
            if ($prev !== null && $this->paths->currentTag($name) !== $prev) {
                try { $this->switchCurrent($name,$prev,$log); $this->reloadRuntime($spec,$log); }
                catch (\Throwable $restoreError) { $log->line('復元処理のエラー: '.$restoreError->getMessage()); }
            }
            $log->finish('failed',$e->getMessage());
        } finally { if ($lock) { flock($lock,LOCK_UN); fclose($lock); } }
    }

    /**
     * デプロイを伴わない取り込み。いま /var/www/<name> にあるファイルをそのまま
     * ひとつのリリースにして current を張る。導入時の移行用で、新しいアーカイブを
     * 用意しなくても既存サイトを管理下に置ける。
     */
    public function adopt(array $params): array
    {
        $jobId = (int) ($params['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new OpException('job_id がありません');
        }
        $spec = Spec::service($params['service'] ?? null);
        $name = $spec['name'];
        $plan = (new Daemon($this->cfg))->migrationPlan($name, (array) ($params['daemons'] ?? []));

        if ($this->paths->currentTag($name) !== null) {
            throw new OpException('すでに管理下のリリースが公開されています。取り込みは初回のみ行えます');
        }

        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new OpException('取り込みプロセスを起動できません');
        }
        if ($pid === 0) {
            posix_setsid();
            $log = new JobLog($jobId, (string) $this->cfg['job_dir']);
            $lock = null;
            try {
                $lock = $this->lockSite($name);
                if ($this->paths->currentTag($name) !== null) throw new OpException('すでに公開済みです');
                $log->line("adopt {$name}: " . $this->paths->base($name) . ' の中身を取り込みます');

                $tag = date('Ymd-His');
                if (is_dir($this->paths->release($name, $tag))) {
                    $tag = $this->adoptTag($name, $tag);
                }
                $adopted = $this->adoptExisting($name, $tag, $log);
                if ($adopted === null) {
                    throw new OpException(
                        '取り込めるファイルがありません。'
                        . '公開したいファイルを置いてから実行するか、通常のデプロイを使ってください'
                    );
                }

                $this->linkShared($name, $spec, $this->paths->release($name, $adopted['tag']), $log);
                $this->switchCurrent($name, $adopted['tag'], $log);
                $log->set('adopted', ['tag' => $adopted['tag'], 'state' => 'current'] + $adopted['release']);
                (new Daemon($this->cfg))->migrateForSite($name, $plan, fn($m) => $log->line($m));

                // 公開設定を反映する前でも実行できる操作なので、ヘルスチェックは
                // 結果をログに残すだけにする (この時点の NG は「まだ反映していない」の
                // 意味にしかならず、サイトの状態として記録すると誤解を招くため)。
                if (!$this->health->poll($spec, fn($m) => $log->line($m))) {
                    $log->line('まだ応答がありません。「公開設定」を反映すると配信が切り替わります');
                }
                $log->finish('succeeded', "既存ファイルを releases/{$adopted['tag']} として取り込みました");
            } catch (\Throwable $e) {
                $log->finish('failed', $e->getMessage());
            } finally { if ($lock) { flock($lock,LOCK_UN); fclose($lock); } }
            exit(0);
        }
        return ['job_id' => $jobId, 'pid' => $pid];
    }

    // ---------------------------------------------------------------- 本体

    private function run(int $jobId, array $spec, array $source, array $opts): void
    {
        $lock = null;
        try { $lock = $this->lockSite($spec['name']); $this->runUnlocked($jobId,$spec,$source,$opts); }
        catch (\Throwable $e) { (new JobLog($jobId,(string)$this->cfg['job_dir']))->finish('failed',$e->getMessage()); throw $e; }
        finally { if ($lock) { flock($lock,LOCK_UN); fclose($lock); } }
    }

    private function runUnlocked(int $jobId, array $spec, array $source, array $opts): void
    {
        $name = $spec['name'];
        $tag  = date('Ymd-His');
        $log  = new JobLog($jobId, (string) $this->cfg['job_dir']);
        $prev = $this->paths->currentTag($name);
        $relDir = $this->paths->release($name, $tag);
        $adopted = null;

        $log->line("deploy {$name} tag={$tag} source={$source['type']} 直前=" . ($prev ?? 'なし'));

        try {
            if ($opts['maintenance']) {
                touch($this->paths->shared($name) . '/maintenance.on');
                $log->line('メンテナンスモード ON (shared/maintenance.on)');
            }

            // [0.5] 初回デプロイなら、既にディレクトリにあるファイルを 1 リリースとして引き継ぐ
            if ($prev === null) {
                $adopted = $this->adoptExisting($name, $this->adoptTag($name, $tag), $log);
                if ($adopted !== null) {
                    // current を取り込んだリリースへ向けておくと、この後どこで失敗しても
                    // 管理下のパス (current/<docroot>) で既存サイトが配信され続ける。
                    $this->linkShared($name,$spec,$this->paths->release($name,$adopted['tag']),$log);
                    $this->switchCurrent($name, $adopted['tag'], $log);
                    $prev = $adopted['tag'];
                    $log->line("引き継ぎ完了: releases/{$adopted['tag']} (自動ロールバック先になります)");
                }
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
                $argv = Spec::runtimeHook($argv, $spec['runtime']);
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
            if (!empty($opts['apply_vhost'])) {
                $log->line('初回の公開設定を反映します');
                $conf = $source['manifest_plan']['apache'] ?? '';
                if ($conf !== '') $this->vhost->applyRepositoryConfig($conf,$spec);
                else $this->vhost->apply(['service' => $spec]);
            }
            $this->reloadRuntime($spec, $log);

            if ($opts['maintenance']) {
                @unlink($this->paths->shared($name) . '/maintenance.on');
                $log->line('メンテナンスモード OFF');
            }

            if (!empty($source['manifest_plan'])) {
                (new ManifestImport($this->cfg))->installPrograms($name,fn($m)=>$log->line($m));
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

            // [10] 掃除 (この回に引き継いだリリースは切り戻し先として残す)
            $pruned = $this->prune($name, $spec['keep_releases'], $log, $adopted['tag'] ?? null);

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
            if ($opts['auto_rollback'] && $prev !== null && $this->paths->currentTag($name) === $tag) {
                try {
                    $log->line('切替後の処理に失敗したため、以前のバージョンと常駐プログラムへ戻します');
                    $this->switchCurrent($name, $prev, $log);
                    $this->reloadRuntime($spec, $log);
                    $log->set('release', ['tag' => $prev, 'state' => 'current', 'source' => 'auto-rollback']);
                } catch (\Throwable $rollbackError) { $log->line('復元処理のエラー: ' . $rollbackError->getMessage()); }
            }
            if ($this->paths->currentTag($name) === $tag && isset($meta)) {
                $log->set('release', ['tag' => $tag, 'state' => 'current', 'label' => $opts['label'],
                    'source' => $meta['source'], 'sha256' => $meta['sha256'], 'size' => $meta['size']]);
            }
            @unlink($this->paths->shared($name) . '/maintenance.on');
            // 引き継いだリリースが公開されたまま終わる場合は、その事実を web に伝える
            // (引き継ぎ分は既存サイトそのものなので、失敗しても消してはいけない)
            if ($adopted !== null && $this->paths->currentTag($name) === $adopted['tag']) {
                $log->set('adopted', ['tag' => $adopted['tag'], 'state' => 'current'] + $adopted['release']);
            }
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
            $repo = (new GitRepositories($this->cfg))->resolve($spec['name']);
            $ref = (string) ($src['ref'] ?? '');
            try { $ref = \Pjas\Domain\GitHubRepository::ref($ref); }
            catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
            $plan = (new ManifestImport($this->cfg))->plan($spec['name']);
            if ($plan && !$plan['done']) $repo['manifest_plan'] = $plan;
            return ['type' => 'git', 'ref' => $ref] + $repo;
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
        if (!empty($src['manifest_plan'])) {
            $plan = $src['manifest_plan'];
            if (!is_dir($plan['checkout'])) throw new OpException('初回設定の取得ファイルがありません');
            if (!mkdir($relDir,0755,true)) throw new OpException('公開先を作成できません');
            Proc::must(['/bin/cp','-a','--',$plan['checkout'] . '/.',$relDir],null,120);
            $this->assertNoEscape($relDir,$log);
            Proc::must(['/bin/rm','-rf','--',$relDir . '/.git'],null,120);
            $log->line('.pjasを確認したコミット ' . $plan['sha'] . ' を使用します');
            return ['source'=>'git:' . $plan['sha'],'sha256'=>$plan['sha'],'size'=>0];
        }
        $args = GitRepositories::cloneArgs($src, $relDir);
        if (!empty($src['public_github'])) {
            $env = ['PATH' => '/usr/bin:/bin', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '/bin/false', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'];
            $result = Proc::run($args, null, 600, null, $env);
            if ($result['code'] !== 0) throw new OpException('公開GitHubから取得できませんでした。URL・公開設定・ブランチ名を確認してください: ' . trim($result['err'] ?: $result['out']));
        } else { Proc::must($args, null, 600); }
        $this->assertNoEscape($relDir, $log);
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

    // ------------------------------------------------------- 既存サイトの引き継ぎ

    /**
     * 引き継ぎ対象の判定。ファイルを触らないのでテストから直接呼べる。
     *
     * releases/ と shared/ は管理用なので対象外。それ以外の中身 (index.php,
     * public/, .htaccess など既存サイトのファイル) を 1 つのリリースとして扱う。
     * アーカイブ展開と同じく、単一トップディレクトリならその中身をリリース直下に置く
     * (/var/www/billing/billing-app/... のような一段深い構成をそのまま救うため)。
     *
     * @return array{entries:array<int,string>,flatten:?string,current_link:?string}
     */
    public static function adoptPlan(string $base): array
    {
        $entries = [];
        $link    = null;

        foreach (array_diff(scandir($base) ?: [], ['.', '..']) as $e) {
            $path = $base . '/' . $e;
            if ($e === 'releases' || $e === 'shared' || str_starts_with($e, '.current-')) {
                continue;
            }
            if ($e === 'current' && is_link($path)) {
                $target = realpath($path);
                $root   = realpath($base);
                $link   = $target === false ? 'dangling'
                    : (str_starts_with($target . '/', $root . '/') ? 'inside' : 'outside');
                continue;
            }
            $entries[] = $e;
        }
        sort($entries);

        // 中身がひとつのディレクトリだけなら、その中をリリース直下として扱う。
        // ただし public/ のような「公開フォルダそのもの」の名前は畳まない
        // (既存 vhost の DocumentRoot が /var/www/<name>/public を指しているとき、
        //  畳むと docroot の設定と実際の構成がずれるため)
        $flatten = null;
        if (count($entries) === 1 && !in_array($entries[0], self::DOCROOT_NAMES, true)) {
            $only = $base . '/' . $entries[0];
            if (is_dir($only) && !is_link($only)) {
                $flatten = $entries[0];
            }
        }

        return ['entries' => $entries, 'flatten' => $flatten, 'current_link' => $link];
    }

    /**
     * 初回デプロイ時、/var/www/<name> に既に置かれているファイルを releases/<tag>
     * として引き継ぐ。既存サイトを消さずに、切り戻し先と履歴に載せるための処理。
     * 引き継ぐものが無ければ null を返す。
     *
     * @return array{tag:string,count:int,release:array<string,mixed>}|null
     */
    private function adoptExisting(string $name, string $tag, JobLog $log): ?array
    {
        $base = $this->paths->base($name);
        $plan = self::adoptPlan($base);

        // current がサービスの外を指している場合、何を公開しているのか agent 側では
        // 判断できない。黙って壊すより、止めて運用者に判断してもらう。
        if ($plan['current_link'] === 'outside') {
            throw new OpException(
                'current がサービスディレクトリの外を指すシンボリックリンクです。'
                . '手動で退避してから再実行してください: ' . $this->paths->current($name)
            );
        }
        if ($plan['current_link'] !== null) {
            @unlink($this->paths->current($name));
            $log->line('既存の current シンボリックリンクを外しました');
        }
        if (!$plan['entries']) {
            // ファイル移動後、権限調整などで中断した場合は releases 内に実体だけが残る。
            // 再実行時に最新の未接続リリースを引き継ぎとして復旧する。
            $tags = $this->paths->releaseTags($name);
            if ($this->paths->currentTag($name) === null && $tags) {
                $resumeTag = $tags[0];
                $resumeDir = $this->paths->release($name, $resumeTag);
                $resumeEntries = array_values(array_diff(scandir($resumeDir) ?: [], ['.', '..']));
                if ($resumeEntries) {
                    $log->line("中断した取り込み releases/{$resumeTag} を再開します");
                    $this->normalizePerms($resumeDir, $log);
                    $release = [
                        'label'  => '引き継ぎ (既存ファイル)',
                        'source' => 'adopt:existing',
                        'sha256' => '',
                        'size'   => 0,
                    ];
                    $log->set('adopted', ['tag' => $resumeTag, 'state' => 'archived'] + $release);
                    return ['tag' => $resumeTag, 'count' => count($resumeEntries), 'release' => $release];
                }
            }
            return null;
        }

        $dir = $this->paths->release($name, $tag);
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new OpException("引き継ぎ先を作成できません: {$dir}");
        }
        $log->line('既存ファイル ' . count($plan['entries']) . " 件を releases/{$tag} として引き継ぎます");

        foreach ($plan['entries'] as $e) {
            if (!rename($base . '/' . $e, $dir . '/' . $e)) {
                throw new OpException("引き継ぎに失敗しました: {$e}");
            }
        }

        if ($plan['flatten'] !== null) {
            $inner = $dir . '/' . $plan['flatten'];
            foreach (array_diff(scandir($inner) ?: [], ['.', '..']) as $f) {
                if (!rename($inner . '/' . $f, $dir . '/' . $f)) {
                    throw new OpException("引き継ぎに失敗しました: {$plan['flatten']}/{$f}");
                }
            }
            @rmdir($inner);
            $log->line("単一トップディレクトリ {$plan['flatten']}/ をリリース直下として扱います");
        }

        $this->normalizePerms($dir, $log);

        $release = [
            'label'  => '引き継ぎ (既存ファイル)',
            'source' => 'adopt:existing',
            'sha256' => '',
            'size'   => 0,
        ];
        $log->set('adopted', ['tag' => $tag, 'state' => 'archived'] + $release);

        return ['tag' => $tag, 'count' => count($plan['entries']), 'release' => $release];
    }

    /** 引き継ぎ用のタグ。今回のリリースより古い時刻にして履歴の順序を保つ。 */
    private function adoptTag(string $name, string $newTag): string
    {
        $t = \DateTimeImmutable::createFromFormat('Ymd-His', $newTag) ?: new \DateTimeImmutable();
        for ($i = 1; $i <= 120; $i++) {
            $cand = $t->modify("-{$i} second")->format('Ymd-His');
            if (!is_dir($this->paths->release($name, $cand))) {
                return $cand;
            }
        }
        throw new OpException('引き継ぎ用のリリースタグを決められませんでした');
    }

    // ---------------------------------------------------------------- 配置

    private function linkShared(string $name, array $spec, string $relDir, JobLog $log): void
    {
        $data = new SharedDatabases($this->cfg,$this->paths);
        $current = $this->paths->currentTag($name) === null ? null : $this->paths->assertInside($name,$this->paths->current($name));
        // 最初のstorageや.envを空の雛形で潰さず、公開中→新規同梱の順で初期化する。
        foreach ($spec['shared'] as $p) {
            $sharedPath = $data->preparePath($name,$p);
            if (file_exists($sharedPath)) continue;
            foreach (array_filter([$current,$relDir]) as $root) {
                $source = $root . '/' . $p;
                if (!file_exists($source) || is_link($source)) continue;
                $this->paths->assertInside($name,$source);
                if (!rename($source,$sharedPath)) throw new OpException('共有データの初期化に失敗しました: '.$p);
                if ($current !== null && $root === $current && !symlink($sharedPath,$source)) {
                    if (!file_exists($source) && !is_link($source)) @rename($sharedPath,$source);
                    throw new OpException('現在の共有データをつなげません');
                }
                Proc::must(['/bin/chown','-Rh',$this->cfg['web_user'].':'.$this->cfg['web_group'],$sharedPath],null,120);
                break;
            }
        }
        if (!empty($spec['auto_databases'])) {
            $files = SharedDatabases::discover($relDir);
            if ($current !== null) $files = array_merge($files,SharedDatabases::discover($current));
            $files = array_merge($files,SharedDatabases::discover($this->paths->shared($name)));
            (new SharedDatabases($this->cfg,$this->paths))->link($name,$relDir,$files,$current,$log);
        }
        foreach ($spec['shared'] as $p) {
            $sharedPath  = $this->paths->shared($name) . '/' . $p;
            $releasePath = $relDir . '/' . $p;

            clearstatcache(true);
            if (realpath($releasePath) !== false && realpath($releasePath) === realpath($sharedPath)) continue;

            if (!file_exists($sharedPath)) {
                @mkdir(dirname($sharedPath), 0755, true);
                if (is_link($releasePath)) throw new OpException('共有データの初期値がシンボリックリンクです。実体のパスを指定してください: '.$p);
                if (str_contains(basename($p), '.')) {
                    touch($sharedPath);
                } else {
                    mkdir($sharedPath, 0775, true);
                }
                if (!chown($sharedPath,$this->cfg['web_user']) || !chgrp($sharedPath,$this->cfg['web_group'])) throw new OpException('共有データの所有者を設定できません');
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
            if ($r['code'] !== 0) throw new OpException('PHPの再読み込みに失敗しました');
        }
        $r = Proc::run([$this->cfg['apachectl'], 'graceful'], null, 60);
        $log->proc('apachectl graceful', $r);
        if ($r['code'] !== 0) throw new OpException('Apacheの再読み込みに失敗しました');
        (new Daemon($this->cfg))->restartForSite($spec['name'], fn($m) => $log->line($m));
    }

    /** @return array<int,string> 削除したタグ */
    private function prune(string $name, int $keep, JobLog $log, ?string $protect = null): array
    {
        $tags    = $this->paths->releaseTags($name);
        $current = $this->paths->currentTag($name);
        $pruned  = [];
        foreach (array_slice($tags, $keep) as $old) {
            if ($old === $current || $old === $protect) {
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
