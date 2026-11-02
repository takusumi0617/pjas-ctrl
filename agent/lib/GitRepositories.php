<?php
declare(strict_types=1);
namespace Pjas\Agentd;

require_once dirname(__DIR__, 2) . '/app/Domain/GitHubRepository.php';
use Pjas\Domain\GitHubRepository;
require_once __DIR__.'/GitHubApp.php';

/** 公開GitHubの取得元をroot所有で保存する。DBやWebからの任意URLは取得しない。 */
final class GitRepositories
{
    public function __construct(private array $cfg) {}
    private function path(string $name): string
    {
        return rtrim($this->cfg['github_repo_dir'] ?? '/var/lib/pjas-repositories', '/') . '/' . Spec::name($name) . '.json';
    }
    public function register(string $name, string $input, string $auth = 'public', bool $replace = false): void
    {
        try { $url = GitHubRepository::normalize($input); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        if (!in_array($auth,['public','github_app'],true)) throw new OpException('GitHubへの接続方法を選んでください');
        $path = $this->path($name);
        if (is_file($path) && !$replace) {
            if ($this->resolve($name)['repo'] !== $url) throw new OpException('このサイトには別の取得元が登録されています');
            return;
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) throw new OpException('取得元の保存フォルダを作成できません');
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, json_encode(['repo' => $url, 'github_auth'=>$auth], JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new OpException('取得元を保存できません');
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new OpException('取得元を保存できません'); }
    }
    public function resolve(string $name): array
    {
        $path = $this->path($name);
        if (is_file($path)) {
            $saved = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            try { $url = GitHubRepository::normalize((string) ($saved['repo'] ?? '')); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
            return ['repo' => $url, 'public_github' => true, 'github_auth'=>$saved['github_auth'] ?? 'public'];
        }
        $url = $this->cfg['git_repos'][$name] ?? '';
        if (!$url) throw new OpException('このサイトにはGitの取得元が登録されていません: ' . $name);
        return ['repo' => (string) $url, 'public_github' => false];
    }
    public function clone(array $source, string $dest): array
    {
        $work = static function (array $env) use ($source,$dest): array {
            $r=Proc::run(self::cloneArgs($source,$dest),null,600,null,$env);
            if ($r['code']!==0 || !preg_match('/\A[a-f0-9]{40}\z/iD',$source['ref'])) return $r;
            $args=self::cloneArgs($source,$dest);$prefix=array_slice($args,0,array_search('clone',$args,true));
            $r=Proc::run(array_merge($prefix,['-C',$dest,'fetch','--depth=1','--no-tags','origin',$source['ref']]),null,600,null,$env);
            if($r['code']!==0)return $r;
            return Proc::run(array_merge($prefix,['-C',$dest,'checkout','--detach',$source['ref']]),null,60,null,$env);
        };
        if (($source['github_auth']??'public')==='github_app') return (new GitHubApp($this->cfg))->withCredentials($source['repo'],$work);
        return $work(!empty($source['public_github'])?self::publicEnv():[]);
    }
    public function connection(array $params): array
    {
        $name=Spec::name($params['service']['name']??'');
        if (($params['action']??'')==='save') $this->register($name,(string)($params['repo']??''),(string)($params['auth']??'public'),true);
        try { $s=$this->resolve($name); return ['repo'=>$s['repo'],'auth'=>$s['github_auth']??'public']; }
        catch(OpException $e) { if(($params['action']??'')==='save')throw $e;return ['repo'=>'','auth'=>'public']; }
    }
    public static function publicEnv(): array
    {
        return ['PATH'=>'/usr/bin:/bin','GIT_TERMINAL_PROMPT'=>'0','GIT_ASKPASS'=>'/bin/false','GIT_CONFIG_NOSYSTEM'=>'1','GIT_CONFIG_GLOBAL'=>'/dev/null'];
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
        if ($source['ref'] !== 'HEAD' && !preg_match('/\A[a-f0-9]{40}\z/iD',$source['ref'])) $args = array_merge($args, ['--branch', $source['ref']]);
        return array_merge($args, ['--', $source['repo'], $dest]);
    }
}
