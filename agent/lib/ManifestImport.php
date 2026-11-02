<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once dirname(__DIR__, 2) . '/app/Domain/Hooks.php';
require_once dirname(__DIR__, 2) . '/app/Domain/ScheduledTaskRepo.php';
require_once dirname(__DIR__, 2) . '/app/Domain/PjasManifest.php';
require_once __DIR__ . '/GitRepositories.php';
require_once __DIR__ . '/InheritedVhost.php';
require_once __DIR__ . '/DaemonDefinition.php';
use Pjas\Domain\PjasManifest;
use Pjas\Domain\GitHubRepository;
use Pjas\Agentd\Ops\Vhost;

final class ManifestImport
{
    public function __construct(private array $cfg) {}
    private function root(): string { return rtrim($this->cfg['github_repo_dir'] ?? '/var/lib/pjas-repositories', '/'); }
    private function checkout(string $token): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $token)) throw new OpException('読み込み情報が不正です');
        $dir = $this->root() . '/previews/' . $token;
        if (!is_dir($dir)) throw new OpException('読み込み情報がありません。もう一度GitHubから設定を読み込んでください');
        return $dir;
    }
    private function file(string $checkout, string $path): string
    {
        try { PjasManifest::path($path); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        $real = realpath($checkout . '/' . $path);
        if (!$real || !str_starts_with($real, realpath($checkout) . '/') || !is_file($real) || is_link($checkout . '/' . $path) || filesize($real) > 1024*1024) throw new OpException('設定ファイルはリポジトリ内の通常ファイルを指定してください: ' . $path);
        return (string) file_get_contents($real);
    }
    public function inspect(array $params): array
    {
        try { $repo = GitHubRepository::normalize((string) ($params['repo'] ?? '')); $ref = GitHubRepository::ref((string) ($params['ref'] ?? '')); }
        catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        $auth=(string)($params['auth']??'public');
        if(!in_array($auth,['public','github_app'],true))throw new OpException('GitHubへの接続方法を選んでください');
        $token = bin2hex(random_bytes(16));
        $dir = $this->root() . '/previews/' . $token;
        if (!is_dir(dirname($dir)) && !mkdir(dirname($dir), 0700, true)) throw new OpException('読み込み先を作成できません');
        $r = (new GitRepositories($this->cfg))->clone(['repo'=>$repo,'ref'=>$ref,'public_github'=>true,'github_auth'=>$auth],$dir);
        if ($r['code'] !== 0) { Proc::run(['/bin/rm','-rf','--',$dir]); throw new OpException('GitHubから取得できません: ' . trim($r['err'])); }
        $sha = trim(Proc::must(['/usr/bin/git','rev-parse','HEAD'], $dir));
        file_put_contents($dir . '.json', json_encode(['repo'=>$repo,'sha'=>$sha,'ref'=>$ref,'auth'=>$auth], JSON_THROW_ON_ERROR));
        chmod($dir . '.json',0600);
        if (!is_file($dir . '/.pjas')) {
            Proc::run(['/bin/rm','-rf','--',$dir]); @unlink($dir . '.json');
            return ['repo'=>$repo,'sha'=>$sha,'found'=>false];
        }
        $json = $this->file($dir, '.pjas');
        try { $m = PjasManifest::parse($json); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        $conf = isset($m['apache']) ? $this->file($dir, $m['apache']['file']) : '';
        return ['token'=>$token,'repo'=>$repo,'sha'=>$sha,'found'=>true,'json'=>$json,'defaults'=>PjasManifest::defaults($m),'apache'=>$conf];
    }
    public function validate(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $dir = $this->checkout((string)($params['token'] ?? ''));
        $origin = json_decode((string)file_get_contents($dir . '.json'),true,512,JSON_THROW_ON_ERROR);
        try {
            if (GitHubRepository::normalize((string)($params['repo'] ?? '')) !== $origin['repo']) throw new OpException('取得元が読み込み時から変更されています');
            $m = PjasManifest::parse((string)($params['json'] ?? ''));
            $original = PjasManifest::parse($this->file($dir,'.pjas'));
        } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        if (($origin['auth']??'public')!==($params['auth']??'public'))throw new OpException('接続方法が変わりました。設定を再読み込みしてください');
        if (isset($origin['ref']) && GitHubRepository::ref((string)($params['ref'] ?? '')) !== $origin['ref']) throw new OpException('ブランチが読み込み時から変更されています。設定を再読み込みしてください');
        if (($m['apache']['file'] ?? '') !== ($original['apache']['file'] ?? '')) throw new OpException('Apache設定ファイルの変更には再読み込みが必要です');
        if (isset($m['apache'])) self::apache($this->file($dir,$m['apache']['file']),$m,$spec,$this->cfg['www_root']);
        foreach ($m['cron'] as $item) { Spec::cronSchedule($item['schedule']); Spec::cronCommand($item['command']); }
        return ['valid'=>true];
    }
    /** 登録確定時だけ呼ぶ。プレビューと同じコミットのファイルを使う。 */
    public function configure(array $params): array
    {
        $spec = Spec::service($params['service'] ?? null);
        $dir = $this->checkout((string) ($params['token'] ?? ''));
        $origin = json_decode((string) file_get_contents($dir . '.json'), true, 512, JSON_THROW_ON_ERROR);
        if ((new GitRepositories($this->cfg))->resolve($spec['name'])['repo'] !== $origin['repo']) throw new OpException('取得元と読み込み情報が一致しません');
        try { $m = PjasManifest::parse((string) ($params['json'] ?? '')); } catch (\InvalidArgumentException $e) { throw new OpException($e->getMessage()); }
        foreach ($m['cron'] as $cron) { Spec::cronSchedule($cron['schedule']); Spec::cronCommand($cron['command']); }
        $conf = isset($m['apache']) ? $this->file($dir, $m['apache']['file']) : '';
        $originalManifest = PjasManifest::parse($this->file($dir,'.pjas'));
        if (($m['apache']['file'] ?? '') !== ($originalManifest['apache']['file'] ?? '')) throw new OpException('Apache設定のファイルを変える場合は、リポジトリを更新して再度読み込んでください');
        if ($conf !== '') $conf = self::apache($conf, $m, $spec, $this->cfg['www_root']);
        $tasks = $params['tasks'] ?? [];
        if (!is_array($tasks) || count($tasks) !== count($m['cron'])) throw new OpException('定期処理の登録情報が一致しません');
        foreach ($tasks as $i=>$task) {
            Spec::taskId($task['id'] ?? null);
            if (($task['service'] ?? '') !== $spec['name'] || $task['command'] !== PjasManifest::command($m['cron'][$i]['command'],$m,rtrim($this->cfg['www_root'],'/') . '/' . $spec['name']) || $task['schedule'] !== $m['cron'][$i]['schedule']) throw new OpException('定期処理の登録内容が一致しません');
        }
        $plan = ['checkout'=>$dir,'sha'=>$origin['sha'],'manifest'=>$m,'apache'=>$conf,'tasks'=>$tasks,'done'=>false,'daemons'=>[]];
        $path = $this->root() . '/plan-' . $spec['name'] . '.json';
        if (is_file($path)) throw new OpException('初回設定は登録済みです');
        $this->savePlan($spec['name'], $plan);
        return ['sha'=>$origin['sha']];
    }
    public function plan(string $name): ?array
    {
        $path = $this->root() . '/plan-' . Spec::name($name) . '.json';
        return is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
    }
    public function savePlan(string $name, array $plan): void
    {
        $path = $this->root() . '/plan-' . Spec::name($name) . '.json';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) throw new OpException('初回設定を保存できません');
        chmod($tmp,0600); if (!rename($tmp,$path)) throw new OpException('初回設定を保存できません');
    }
    public function installPrograms(string $name, callable $log): void
    {
        $lock = fopen($this->root() . '/plan-' . Spec::name($name) . '.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new OpException('初回設定をロックできません');
        try { $this->installProgramsLocked($name,$log); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function installProgramsLocked(string $name, callable $log): void
    {
        $plan = $this->plan($name);
        if (!$plan || $plan['done']) return;
        $daemon = new \Pjas\Agentd\Ops\Daemon($this->cfg);
        $base = rtrim($this->cfg['www_root'],'/') . '/' . $name;
        $source = $plan['manifest']['apache']['source_root'] ?? '/var/www/' . $plan['manifest']['site']['name'];
        $map = static function (string $command) use ($base, $source): string {
            $command = DaemonDefinition::template($command,$source);
            return str_replace($source . '/shared/', $base . '/shared/', $command);
        };
        foreach ($plan['manifest']['daemons'] as $item) {
            if (!isset($plan['daemons'][$item['name']])) {
                $row = $daemon->save(['service'=>$name,'mode'=>'new','label'=>$item['label'] ?? $item['name'],
                    'command'=>$map($item['command']),'working_subdir'=>$item['working_directory'] ?? '']);
                $plan['daemons'][$item['name']] = $row['unit'];
                $this->savePlan($name,$plan);
            }
            $unit = $plan['daemons'][$item['name']];
            if (!empty($item['start_on_boot'])) $daemon->enableBoot($name,$unit);
            if (!empty($item['start_on_install'])) $daemon->action(['service'=>$name,'unit'=>$unit,'action'=>'start']);
            $log('常駐プログラムを登録: ' . $item['name']);
        }
        foreach ($plan['tasks'] as $task) {
            $task['command'] = DaemonDefinition::expand($map($task['command']),$base . '/current');
            (new \Pjas\Agentd\Ops\ScheduledTask($this->cfg,new Paths($this->cfg['www_root'])))->apply(['task'=>$task]);
            $log('定期処理を登録: ' . $task['name']);
        }
        $plan['done'] = true;
        $this->savePlan($name,$plan);
        Proc::run(['/bin/rm','-rf','--',$plan['checkout']],null,120);
        @unlink($plan['checkout'] . '.json');
    }
    public static function apache(string $body, array $m, array $spec, string $www): string
    {
        // Apache全文は管理者がプレビューで確認する。サーバー全体や外部ファイルの読み込みは対象外。
        if (preg_match('/^\s*(?:Include\w*|LoadModule|LoadFile|User|Group|Perl\w*|Lua\w*|Define|UnDefine)\b/im', $body)
            || preg_match('/^\s*(?:ErrorLog|CustomLog|TransferLog)\s+["\x27]?\|/im', $body)
            || preg_match('/\\\\\s*\R/', $body)) throw new OpException('リポジトリのconfには外部設定の読み込み・実行・継続行は指定できません');
        if (!preg_match('/<VirtualHost\b/i',$body)) {
            $body = '<VirtualHost *:' . (!empty($spec['tls']) ? '443' : '80') . ">\nServerName {$spec['domain']}\n" . $body . "\n</VirtualHost>\n";
        }
        $outside = preg_replace('~^\s*<VirtualHost\s+[^>]+>\s*\R.*?^\s*</VirtualHost>[^\S\r\n]*$~ims','',$body);
        foreach (preg_split('/\R/', $outside) as $line) if (trim($line) !== '' && !str_starts_with(trim($line),'#') && !preg_match('~^</?IfModule(?:\s+[a-zA-Z0-9_.!-]+)?>$~',trim($line))) throw new OpException('リポジトリのconfはVirtualHost内に設定を記載してください');
        if (!preg_match('/^\s*ServerName\s+(\S+)/mi',$body,$host)) throw new OpException('confにServerNameを指定してください');
        $source = $m['apache']['source_root'];
        $targetBase = rtrim($www,'/') . '/' . $spec['name'];
        $body = preg_replace_callback('~' . preg_quote($source,'~') . '(?=/|[\s"\x27<>]|$)~',static fn()=> $targetBase,$body);
        // ここで検証し、保存する本文は元構造を維持。実際の証明書などは反映時に生成する。
        InheritedVhost::render($body,$spec,$targetBase . '/current','/certificate','/key',$host[1]);
        return $body;
    }
}
