<?php
declare(strict_types=1);
namespace Pjas\Agentd;

require_once dirname(__DIR__, 2) . '/app/Domain/GitHubRepository.php';
use Pjas\Domain\GitHubRepository;

/** 公開GitHubの取得元をroot所有で保存する。DBやWebからの任意URLは取得しない。 */
final class GitRepositories
{
    public function __construct(private array $cfg) {}
    private function path(string $name): string
    {
        return rtrim($this->cfg['github_repo_dir'] ?? '/var/lib/pjas-repositories', '/') . '/' . Spec::name($name) . '.json';
    }
    public function register(string $name, string $input): void
    {
        try { $url = GitHubRepository::normalize($input); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        $path = $this->path($name);
        if (is_file($path)) {
            if ($this->resolve($name)['repo'] !== $url) throw new OpException('このサイトには別の取得元が登録されています');
            return;
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) throw new OpException('取得元の保存フォルダを作成できません');
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, json_encode(['repo' => $url], JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new OpException('取得元を保存できません');
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new OpException('取得元を保存できません'); }
    }
    public function resolve(string $name): array
    {
        $path = $this->path($name);
        if (is_file($path)) {
            $saved = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            try { $url = GitHubRepository::normalize((string) ($saved['repo'] ?? '')); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
            return ['repo' => $url, 'public_github' => true];
        }
        $url = $this->cfg['git_repos'][$name] ?? '';
        if (!$url) throw new OpException('このサイトにはGitの取得元が登録されていません: ' . $name);
        return ['repo' => (string) $url, 'public_github' => false];
    }
    public static function cloneArgs(array $source, string $dest): array
    {
        $args = ['/usr/bin/git'];
        if (!empty($source['public_github'])) {
            $args = array_merge($args, ['-c', 'credential.helper=', '-c', 'core.askPass=/bin/false',
                '-c', 'http.extraHeader=', '-c', 'http.followRedirects=false', '-c', 'core.hooksPath=/dev/null',
                '-c', 'protocol.allow=never', '-c', 'protocol.https.allow=always']);
        }
        $args = array_merge($args, ['clone', '--depth', '1']);
        if ($source['ref'] !== 'HEAD') $args = array_merge($args, ['--branch', $source['ref']]);
        return array_merge($args, ['--', $source['repo'], $dest]);
    }
}
