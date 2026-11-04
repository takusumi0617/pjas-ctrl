#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * pjas-agentd — 特権操作を行う唯一のプロセス (root)。
 *
 * Unix ソケットで 1 行 1 リクエストの JSON を受け取り、
 * 登録済みの op だけを実行する。任意コマンド文字列は受け取らない。
 *
 *   要求: {"op":"deploy.start","params":{...},"actor":"me@example.com","request_id":"…"}
 *   応答: {"ok":true,"data":{...}}  /  {"ok":false,"error":"…"}
 */

namespace Pjas\Agentd;

use Pjas\Agentd\Ops\Daemon;
use Pjas\Agentd\Ops\Deploy;
use Pjas\Agentd\Ops\Fs;
use Pjas\Agentd\Ops\Health;
use Pjas\Agentd\Ops\Service;
use Pjas\Agentd\Ops\ScheduledTask;
use Pjas\Agentd\Ops\Vhost;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
foreach (['pcntl', 'posix'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "拡張 {$ext} が必要です\n");
        exit(1);
    }
}
if (posix_geteuid() !== 0) {
    fwrite(STDERR, "pjas-agentd は root で実行してください\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Pjas\\Agentd\\')) {
        return;
    }
    $rel = substr($class, strlen('Pjas\\Agentd\\'));
    foreach (['lib/' . $rel, 'ops/' . str_replace('Ops\\', '', $rel)] as $cand) {
        $file = __DIR__ . '/' . str_replace('\\', '/', $cand) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

$cfg   = require __DIR__ . '/config.php';
$paths = new Paths((string) $cfg['www_root']);

$vhost   = new Vhost($cfg, $paths);
$health  = new Health($cfg);
$deploy  = new Deploy($cfg, $paths, $vhost, $health);
$service = new Service($cfg, $paths);
$fs      = new Fs($cfg, $paths);
$cron    = new ScheduledTask($cfg, $paths);
$daemon  = new Daemon($cfg);

/** op 登録表。ここに無いものは実行できない。 */
$manifest = new \Pjas\Agentd\ManifestImport($cfg);
$operations=new \Pjas\Agentd\Ops\Operations($cfg,$paths);
$backups=new \Pjas\Agentd\Ops\Backups($cfg,$paths);
$githubApp=new \Pjas\Agentd\GitHubApp($cfg);
$review=new \Pjas\Agentd\ReleaseReview($cfg,$paths);
$preview=new \Pjas\Agentd\Ops\Preview($cfg,$paths);
$repositories=new \Pjas\Agentd\GitRepositories($cfg);
$server=new \Pjas\Agentd\Ops\ServerMonitor($cfg);
$registry = [
    'server.snapshot'=>[$server,'snapshot'],
    'server.restart'=>[$server,'restart'],
    'ops.run'=>[(new \Pjas\Agentd\Ops\AsyncTasks($cfg,$paths)),'start'],
    'ops.settings'=>[$operations,'settings'],
    'ops.save'=>[$operations,'saveSettings'],
    'ops.notify'=>[$operations,'notify'],
    'ops.resources'=>[$operations,'resources'],
    'ops.certificate'=>[$operations,'certificate'],
    'ops.logs'=>[$operations,'logs'],
    'github.app.status'=>static fn(array $p):array=>$githubApp->status(),
    'github.app.save'=>[$githubApp,'save'],
    'github.app.convert'=>[$githubApp,'convert'],
    'github.app.test'=>[$githubApp,'test'],
    'github.webhook.verify'=>[$githubApp,'verifyRequest'],
    'github.connection'=>[$repositories,'connection'],
    'backup.list'=>[$backups,'listing'],
    'backup.create'=>[$backups,'create'],
    'backup.restore'=>[$backups,'restore'],
    'backup.download'=>[$backups,'download'],
    'backup.key'=>[$backups,'keyInfo'],
    'backup.prune'=>[$backups,'prune'],
    'deploy.cleanup'=>[$review,'cleanup'],
    'deploy.prepare'=>[$deploy,'prepareStart'],
    'deploy.review'=>[$review,'details'],
    'deploy.preparations'=>[$review,'listing'],
    'deploy.diff'=>[$review,'diff'],
    'apache.restore'=>[$vhost,'restore'],
    'apache.history'=>static fn(array $p):array=>['history'=>(new \Pjas\Agentd\ApacheHistory($cfg))->listing(\Pjas\Agentd\Spec::name($p['service']['name']??''))],
    'preview.save'=>[$preview,'save'],
    'preview.status'=>[$preview,'status'],
    'preview.publish'=>[$preview,'publish'],
    'preview.credentials'=>[$preview,'credentials'],
    'preview.remove'=>[$preview,'remove'],
    'github.validate' => [$manifest,'validate'],
    'github.inspect' => [$manifest,'inspect'],
    'github.configure' => [$manifest,'configure'],
    'ping'            => static fn(array $p): array => ['pong' => true, 'version' => '1.0.0'],
    'service.scan'    => [$service, 'scan'],
    'service.create'  => [$service, 'create'],
    'site.plan'=>[(new \Pjas\Agentd\Ops\SiteLifecycle($cfg,$paths)),'plan'],
    'site.status'=>[(new \Pjas\Agentd\Ops\SiteLifecycle($cfg,$paths)),'status'],
    'site.start'=>[(new \Pjas\Agentd\Ops\SiteLifecycle($cfg,$paths)),'start'],
    'site.cleanup'=>[(new \Pjas\Agentd\Ops\SiteLifecycle($cfg,$paths)),'cleanupStart'],
    'service.destroy' => [$service, 'destroy'],
    'deploy.start'    => [$deploy, 'start'],
    'deploy.releases' => [$deploy, 'releases'],
    'deploy.rollback' => [$deploy, 'rollback'],
    'deploy.adopt'    => [$deploy, 'adopt'],
    'apache.pause.status' => [$vhost, 'pauseStatus'],
    'apache.pause'    => [$vhost, 'pause'],
    'apache.render'   => [$vhost, 'preview'],
    'apache.apply'    => [$vhost, 'apply'],
    'apache.reload'   => static fn(array $p): array => $vhost->reload(),
    'fpm.reload'      => static function (array $p) use ($cfg): array {
        $spec = Spec::service($p['service'] ?? null);
        if ($spec['runtime'] === 'static') {
            return ['skipped' => true];
        }
        return ['out' => Proc::must([$cfg['systemctl'], 'reload', $spec['runtime']], null, 60)];
    },
    'fs.list'         => [$fs, 'list'],
    'fs.read'         => [$fs, 'read'],
    'fs.write'        => [$fs, 'write'],
    'health.check'    => [$health, 'check'],
    'daemon.candidates' => [$daemon, 'candidates'],
    'daemon.save'     => [$daemon, 'save'],
    'daemon.list'     => [$daemon, 'listing'],
    'daemon.action'   => [$daemon, 'action'],
    'cron.apply'      => [$cron, 'apply'],
    'cron.delete'     => [$cron, 'delete'],
];

// ---------------------------------------------------------------- ソケット

$sockPath = (string) $cfg['socket'];
if (file_exists($sockPath)) {
    unlink($sockPath);
}
$server = stream_socket_server('unix://' . $sockPath, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "ソケットを開けません {$sockPath}: {$errstr}\n");
    exit(1);
}
chgrp($sockPath, (string) $cfg['socket_group']);
chmod($sockPath, (int) $cfg['socket_mode']);

foreach ([(string) $cfg['job_dir'], (string) $cfg['upload_dir']] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}
chgrp((string) $cfg['upload_dir'], (string) $cfg['socket_group']);
chmod((string) $cfg['upload_dir'], 0770);

pcntl_async_signals(true);
$running = true;
foreach ([SIGTERM, SIGINT] as $sig) {
    pcntl_signal($sig, static function () use (&$running): void { $running = false; });
}
// デプロイ用の子プロセスを回収する
pcntl_signal(SIGCHLD, static function (): void {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
    }
});

openlog('pjas-agentd', LOG_PID, LOG_DAEMON);
syslog(LOG_INFO, "listening on {$sockPath}");

while ($running) {
    $conn = @stream_socket_accept($server, 5);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 900);

    $line = fgets($conn, 1024 * 1024);
    $res  = handle($line === false ? '' : $line, $registry);
    fwrite($conn, json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fclose($conn);
}

syslog(LOG_INFO, 'shutting down');
fclose($server);
@unlink($sockPath);
exit(0);

/**
 * 1 リクエストを処理する。例外はすべてここで応答に変換する
 * (詳細は syslog にのみ残し、web には要約を返す)。
 */
function handle(string $line, array $registry): array
{
    global $cfg;
    $req = json_decode(trim($line), true);
    if (!is_array($req) || !isset($req['op'])) {
        return ['ok' => false, 'error' => 'リクエストを解釈できません'];
    }
    $op     = (string) $req['op'];
    $actor  = substr((string) ($req['actor'] ?? 'unknown'), 0, 120);
    $params = is_array($req['params'] ?? null) ? $req['params'] : [];
    $params['_actor'] = $actor;

    if (!isset($registry[$op])) {
        syslog(LOG_WARNING, "未登録の op を拒否しました: {$op} (actor={$actor})");
        return ['ok' => false, 'error' => "未登録の op です: {$op}"];
    }

    $started = microtime(true);$siteLock=null;
    try {
        $target=$params['service']??($params['task']['service']??null);if(is_array($target))$target=$target['name']??null;
        if(is_string($target)&&!str_starts_with($op,'site.')){
            if(in_array($op,['github.configure','github.connection','cron.apply','cron.delete','daemon.save','daemon.action','apache.apply','apache.pause','fs.write','service.create','service.destroy','preview.save'],true)){
                $path=$cfg['apache_sites'].'/.pjas-deploy-'.\Pjas\Agentd\Spec::name($target).'.lock';if(is_link($path))throw new OpException('作業ロックが不正です');$siteLock=fopen($path,'c');
                if(!$siteLock||!flock($siteLock,LOCK_EX|LOCK_NB))throw new OpException('このサイトは別の作業中です。終了後にやり直してください');
            }
            \Pjas\Agentd\SiteLifecycleGuard::active($cfg,$target);
        }
        $data = ($registry[$op])($params);
        syslog(LOG_INFO, sprintf('%s ok actor=%s %.1fms', $op, $actor, (microtime(true) - $started) * 1000));
        return ['ok' => true, 'data' => is_array($data) ? $data : []];
    } catch (OpException $e) {
        syslog(LOG_WARNING, "{$op} failed actor={$actor}: " . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        syslog(LOG_ERR, "{$op} error actor={$actor}: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['ok' => false, 'error' => '内部エラーが発生しました (syslog を確認してください)'];
    }finally{if(is_resource($siteLock)){flock($siteLock,LOCK_UN);fclose($siteLock);}}
}
