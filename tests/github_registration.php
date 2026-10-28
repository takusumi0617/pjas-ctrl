<?php
declare(strict_types=1);
foreach (['OpException', 'Spec', 'Paths', 'Proc', 'JobLog', 'GitRepositories'] as $file) require dirname(__DIR__) . '/agent/lib/' . $file . '.php';
foreach (['Service', 'Vhost', 'Health', 'Deploy'] as $file) require dirname(__DIR__) . '/agent/ops/' . $file . '.php';
use Pjas\Domain\GitHubRepository;
use Pjas\Agentd\GitRepositories;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Paths;
use Pjas\Agentd\JobLog;
use Pjas\Agentd\Ops\Service;
use Pjas\Agentd\Ops\Vhost;
use Pjas\Agentd\Ops\Health;
use Pjas\Agentd\Ops\Deploy;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "ok {$label}\n"; }
function rejects(callable $f): void { try { $f(); } catch (InvalidArgumentException|\Pjas\Agentd\OpException) { return; } throw new RuntimeException('拒否されませんでした'); }
foreach (['owner/repo', 'https://github.com/owner/repo', 'https://github.com/owner/repo.git/'] as $url) check(GitHubRepository::normalize($url) === 'https://github.com/owner/repo.git', 'URL正規化: ' . $url);
foreach (['http://github.com/a/b', 'https://github.com.evil.test/a/b', 'https://github.com@evil.test/a/b', 'https://token@github.com/a/b', 'https://github.com/a/b?token=x', 'file:///tmp/repo', 'git@github.com:a/b', 'a/../b', 'https://github.com/a/b/tree/main'] as $url) rejects(fn() => GitHubRepository::normalize($url));
foreach (['--upload-pack=bad', '../main', 'main;id', 'main\nnext'] as $ref) rejects(fn() => GitHubRepository::ref($ref));
check(GitHubRepository::ref('') === 'HEAD', '既定ブランチを自動取得');
$dir = sys_get_temp_dir() . '/pjas-github-' . bin2hex(random_bytes(6));
mkdir($dir); mkdir($dir . '/www'); mkdir($dir . '/repo');
$cfg = ['github_repo_dir' => $dir . '/registry', 'git_repos' => ['legacy' => 'git@example.test:legacy.git'],
    'www_root' => $dir . '/www', 'web_user' => posix_getpwuid(posix_geteuid())['name'], 'web_group' => posix_getgrgid(posix_getegid())['name'], 'apache_sites' => $dir . '/sites'];
try {
    $paths = new Paths($cfg['www_root']);
    (new Service($cfg, $paths))->create(['service' => ['name' => 'new-site', 'shared' => []], 'github_repo' => 'owner/repo']);
    $saved = (new GitRepositories($cfg))->resolve('new-site');
    check($saved['repo'] === 'https://github.com/owner/repo.git' && $saved['public_github'], '新規登録から公開GitHubの取得元を永続化');
    check(!(new GitRepositories($cfg))->resolve('legacy')['public_github'], '従来の事前登録リポジトリも維持');
    rejects(fn() => (new GitRepositories($cfg))->register('new-site', 'other/repo'));
    $args = GitRepositories::cloneArgs($saved + ['ref' => 'HEAD'], $dir . '/checkout');
    check(!in_array('--branch', $args, true) && in_array('credential.helper=', $args, true) && in_array('http.followRedirects=false', $args, true), '既定ブランチと匿名取得の制限を設定');
    check(in_array('--branch', GitRepositories::cloneArgs($saved + ['ref' => 'release/v1'], $dir . '/checkout'), true), '明示的なブランチ・タグも指定可能');
    $deploy = new Deploy($cfg, $paths, new Vhost($cfg, $paths), new Health($cfg));
    $validate = new ReflectionMethod($deploy, 'validateSource');
    $source = $validate->invoke($deploy, ['name' => 'new-site'], ['type' => 'git', 'ref' => 'HEAD', 'repo' => 'file:///ignored']);
    check($source['repo'] === $saved['repo'], '実行時にWebからの任意URLを無視し保存済み取得元を使用');
    Proc::must(['/usr/bin/git', 'init', '-b', 'custom-default', $dir . '/repo']);
    file_put_contents($dir . '/repo/index.html', 'GitHub-style import');
    Proc::must(['/usr/bin/git', 'add', '.'], $dir . '/repo');
    Proc::must(['/usr/bin/git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'initial'], $dir . '/repo');
    $fetch = new ReflectionMethod($deploy, 'fetchGit');
    $log = new JobLog(1, $dir . '/jobs');
    $meta = $fetch->invoke($deploy, 'new-site', ['repo' => $dir . '/repo', 'ref' => 'HEAD', 'public_github' => false], $dir . '/checkout', $log);
    check(file_get_contents($dir . '/checkout/index.html') === 'GitHub-style import' && !is_dir($dir . '/checkout/.git') && strlen($meta['sha256']) === 40, '実際のGit取得処理で既定ブランチを展開しコミットを記録');
    symlink('/etc/passwd', $dir . '/repo/outside');
    Proc::must(['/usr/bin/git', 'add', '.'], $dir . '/repo');
    Proc::must(['/usr/bin/git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'external link'], $dir . '/repo');
    rejects(fn() => $fetch->invoke($deploy, 'new-site', ['repo' => $dir . '/repo', 'ref' => 'HEAD', 'public_github' => false], $dir . '/bad', $log));
    check(true, '取得したコードの外部参照シンボリックリンクを拒否');
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
