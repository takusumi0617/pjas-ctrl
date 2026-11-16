<?php
declare(strict_types=1);

namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Spec;

final class ScheduledTask
{
    public function __construct(private array $cfg, private Paths $paths)
    {
    }

    public function apply(array $params): array
    {
        $task = (array) ($params['task'] ?? []);
        $id = Spec::taskId($task['id'] ?? null);
        if (empty($task['enabled'])) {
            return $this->remove($id) + ['enabled' => false];
        }

        $service = Spec::name($task['service'] ?? '');
        $schedule = Spec::cronSchedule($task['schedule'] ?? '');
        $command = str_replace('{{site_root}}', $this->paths->current($service), Spec::cronCommand($task['command'] ?? ''));
        if(isset($this->cfg['isolation_runtime']))$command=\Pjas\Shared\PhpRuntime::command($command,$this->cfg['isolation_runtime']);
        $webUser = (string) $this->cfg['web_user'];
        $webGroup = (string) $this->cfg['web_group'];

        $scriptDir = (string) ($this->cfg['cron_script_dir'] ?? '/var/lib/pjas/cron');
        $logDir = (string) ($this->cfg['cron_log_dir'] ?? '/var/log/pjas/cron');
        $cronDir = (string) ($this->cfg['cron_dir'] ?? '/etc/cron.d');
        foreach ([$scriptDir, $logDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new OpException("定期処理用フォルダを作成できません: {$dir}");
            }
        }

        if(isset($this->cfg['isolation_site']))foreach([$scriptDir,$logDir] as $dir)\Pjas\Agentd\Proc::must(['/usr/bin/setfacl','-m','u:'.$webUser.':--x',$dir]);
        $script = $scriptDir . "/task-{$id}.sh";
        $log = $logDir . "/task-{$id}.log";
        $cron = $cronDir . "/pjas-task-{$id}";
        $body = "#!/bin/bash\nset -o pipefail\ncd -- " . escapeshellarg($this->paths->current($service)) . "\n" . $command . "\n";
        $this->atomicWrite($script, $body, 0750, 'root', $webGroup);

        if (!file_exists($log) && !touch($log)) {
            throw new OpException("定期処理のログを作成できません: {$log}");
        }
        @chown($log, $webUser);
        @chgrp($log, $webGroup);
        chmod($log, 0640);

        $cronBody = "# managed by pjas-ctrl; task {$id}\nSHELL=/bin/sh\nPATH=/usr/local/bin:/usr/bin:/bin\nMAILTO=\"\"\n";
        $cronBody .= $schedule . ' ' . $webUser . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log) . " 2>&1\n";
        $this->atomicWrite($cron, $cronBody, 0644, 'root', 'root');
        return ['id' => $id, 'cron' => $cron, 'script' => $script, 'log' => $log, 'enabled' => true];
    }

    public function delete(array $params): array
    {
        return $this->remove(Spec::taskId($params['id'] ?? null));
    }

    private function remove(int $id): array
    {
        $cron = (string) ($this->cfg['cron_dir'] ?? '/etc/cron.d') . "/pjas-task-{$id}";
        $script = (string) ($this->cfg['cron_script_dir'] ?? '/var/lib/pjas/cron') . "/task-{$id}.sh";
        foreach ([$cron, $script] as $path) {
            if (file_exists($path) && !unlink($path)) {
                throw new OpException("定期処理の設定を削除できません: {$path}");
            }
        }
        return ['id' => $id, 'removed' => true];
    }

    private function atomicWrite(string $path, string $body, int $mode, string $owner, string $group): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new OpException("定期処理の設定を書き込めません: {$path}");
        }
        chmod($tmp, $mode);
        @chown($tmp, $owner);
        @chgrp($tmp, $group);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new OpException("定期処理の設定を反映できません: {$path}");
        }
    }
}
