<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
require_once dirname(__DIR__).'/lib/ComponentPolicy.php';

use Pjas\Agentd\OpException;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;
use Pjas\Agentd\DaemonDefinition;

/** サイトとの関連付けは root 所有の台帳で管理し、操作ごとに再確認する。 */
final class Daemon
{
    public function __construct(private array $cfg) {}

    private function dir(): string { return $this->cfg['daemon_dir'] ?? '/var/lib/pjas-daemons'; }
    private function unitDir(): string { return $this->cfg['daemon_unit_dir'] ?? '/etc/systemd/system'; }
    private function registry(): array
    {
        $path = $this->dir() . '/registry.json';
        if (!is_file($path)) return [];
        $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows)) throw new OpException('常駐プログラムの台帳が不正です');
        return $rows;
    }
    private function site(mixed $name): string
    {
        $name = Spec::name($name);
        if (!is_dir($this->cfg['www_root'] . '/' . $name . '/current')) {
            throw new OpException('公開中のサイトが見つかりません');
        }
        return $name;
    }
    private function unit(mixed $unit): string
    {
        if (!is_string($unit) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.@-]*\.service\z/D', $unit)
            || $unit === 'pjas-agentd.service') throw new OpException('このサービス名は使えません');
        if (\Pjas\Agentd\ComponentPolicy::reservedUnit($this->cfg,$unit)) throw new OpException('隔離コンポーネントのunitは共通デーモンとして操作できません');
        return $unit;
    }
    private function write(string $path, string $body, int $mode = 0644): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $body, LOCK_EX) === false) throw new OpException('設定を書き込めません');
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new OpException('設定を保存できません'); }
    }
    private function locked(callable $fn): array
    {
        if (!is_dir($this->dir()) && !mkdir($this->dir(), 0755, true)) throw new OpException('台帳フォルダを作成できません');
        $fp = fopen($this->dir() . '/lock', 'c');
        if (!$fp || !flock($fp, LOCK_EX)) throw new OpException('管理台帳をロックできません');
        try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
    }
    private function show(string $unit): array
    {
        $out = Proc::must([$this->cfg['systemctl'], 'show', '--no-pager',
            '--property=LoadState,ActiveState,SubState,UnitFileState,User,Group', '--', $unit], null, 10);
        $state = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (str_contains($line, '=')) { [$k, $v] = explode('=', $line, 2); $state[$k] = $v; }
        }
        return $state;
    }
    private function workingDirectory(string $service, string $subdir): string
    {
        $base = rtrim($this->cfg['www_root'], '/') . '/' . $service;
        $cwd = rtrim($base . '/current/' . $subdir, '/');
        $real = realpath($cwd);
        $root = realpath($base);
        if ($real === false || $root === false || !is_dir($real) || !str_starts_with($real . '/', $root . '/')) {
            throw new OpException('作業フォルダはこのサイト内の存在するフォルダを指定してください');
        }
        return $cwd;
    }

    private function existingInfo(string $unit, string $service): array
    {
        $text = Proc::must([$this->cfg['systemctl'], 'cat', '--no-pager', '--', $unit], null, 10);
        $info = DaemonDefinition::parse($text, $this->cfg['web_user']);
        $base = rtrim($this->cfg['www_root'], '/') . '/' . $service;
        $info['exec_start'] = DaemonDefinition::template($info['exec_start'], $base);
        $cwd = DaemonDefinition::template($info['working_directory'], $base);
        $info['working_subdir'] = $cwd === '{{site_root}}' ? '' : (str_starts_with($cwd, '{{site_root}}/') ? substr($cwd, strlen('{{site_root}}/')) : '');
        if ($cwd !== '{{site_root}}' && !str_starts_with($cwd, '{{site_root}}/')) $info['editable'] = false;
        return $info;
    }

    private function saveExisting(array $row, array $params, ?array $old): array
    {
        $sourceService = $old['service'] ?? $row['service'];
        $info = $this->existingInfo($row['unit'], $sourceService);
        if (!$info['editable']) throw new OpException('実行内容を編集できるのはサイト内で www-data として動く simple/exec 型のプログラムです');
        if ($old === null && empty($params['exec_start'])) {
            $params['exec_start'] = $info['exec_start'];
            $params['working_subdir'] = $info['working_subdir'];
        }
        $command = DaemonDefinition::command(DaemonDefinition::template(trim((string) ($params['exec_start'] ?? $info['exec_start'])), rtrim($this->cfg['www_root'], '/') . '/' . $sourceService));
        $subdir = Spec::relPath($params['working_subdir'] ?? $info['working_subdir'], '作業フォルダ');
        $current = rtrim($this->cfg['www_root'], '/') . '/' . $row['service'] . '/current';
        $cwd = $this->workingDirectory($row['service'], $subdir);
        $expanded = DaemonDefinition::expand($command, $current);
        // シェルを経由せず systemd 本来の ExecStart として実行する。
        $override = "# managed by pjas-ctrl\n[Service]\nWorkingDirectory=" . str_replace('%', '%%', $cwd) . "\nExecStart=\nExecStart=" . $expanded . "\n";
        foreach ($info['path_settings'] as $key => $values) {
            $mapped = array_map(fn($v) => DaemonDefinition::template($v, rtrim($this->cfg['www_root'], '/') . '/' . $sourceService), $values);
            if (!array_filter($mapped, static fn($v) => str_contains($v, '{{site_root}}'))) continue;
            $override .= $key . "=\n";
            foreach ($mapped as $value) $override .= $key . '=' . DaemonDefinition::expand($value, $current) . "\n";
        }
        $dropDir = $this->unitDir() . '/' . $row['unit'] . '.d';
        if (!is_dir($dropDir) && !mkdir($dropDir, 0755, true)) throw new OpException('上書き設定フォルダを作成できません');
        $path = $dropDir . '/zzzz-pjas-ctrl.conf';
        $prior = is_file($path) ? file_get_contents($path) : null;
        try {
            $this->write($path, $override);
            Proc::must([$this->cfg['systemctl'], 'daemon-reload'], null, 15);
            $row['exec_start'] = $command;
            $row['working_subdir'] = $subdir;
            $row['follow_paths'] = true;
            $rows = $this->registry();
            $rows[$row['unit']] = $row;
            $this->write($this->dir() . '/registry.json', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            if ($prior === null) @unlink($path); else $this->write($path, $prior);
            Proc::run([$this->cfg['systemctl'], 'daemon-reload'], null, 15);
            throw $e;
        }
        return $row;
    }

    /** 公開切替後、追従対象の稼働中プログラムだけを再起動する。 */
    public function enableBoot(string $service, string $unit): void
    {
        $this->locked(function () use ($service,$unit): array {
            $unit = $this->unit($unit);
            $row = $this->registry()[$unit] ?? null;
            if (!$row || $row['service'] !== $service || !$row['managed']) throw new OpException('自動起動を設定できないプログラムです');
            Proc::must([$this->cfg['systemctl'],'enable','--',$unit],null,30);
            return [];
        });
    }

    public function restartForSite(string $service, callable $log): void
    {
        if (!is_file($this->dir() . '/registry.json')) return;
        $this->locked(function () use ($service, $log): array {
            foreach ($this->registry() as $row) {
                if ($row['service'] !== $service || empty($row['follow_paths'])) continue;
                $unit = $this->unit($row['unit']);
                $state = $this->show($unit);
                if (!in_array($state['ActiveState'] ?? '', ['active', 'activating'], true)) continue;
                $log('常駐プログラムを新しい公開先で再起動: ' . $unit);
                Proc::must([$this->cfg['systemctl'], 'try-restart', '--', $unit], null, 30);
            }
            return [];
        });
    }

    /** ファイル移動の前に、選択した既存デーモンを検証し起動設定を取り込む。 */
    public function migrationPlan(string $service, array $units): array
    {
        $service = Spec::name($service);
        if (!$units) return [];
        $candidates = $this->candidates([])['units'];
        $plan = [];
        foreach (array_unique($units) as $unit) {
            $unit = $this->unit($unit);
            if (!in_array($unit, $candidates, true)) throw new OpException('移行できない常駐プログラムです: ' . $unit);
            $info = $this->existingInfo($unit, $service);
            if (!$info['editable']) throw new OpException('このサイトの作業フォルダを使う www-data の常駐プログラムを選んでください: ' . $unit);
            $plan[] = ['unit' => $unit, 'exec_start' => $info['exec_start'], 'working_subdir' => $info['working_subdir']];
        }
        return $plan;
    }

    public function migrateForSite(string $service, array $plan, callable $log): void
    {
        foreach ($plan as $entry) {
            $this->save(['service' => $service, 'mode' => 'existing', 'unit' => $entry['unit'],
                'label' => $entry['unit'], 'edit_execution' => true, 'exec_start' => $entry['exec_start'],
                'working_subdir' => $entry['working_subdir']]);
            $log('常駐プログラムの関連付けとパス追従を設定: ' . $entry['unit']);
        }
        $this->restartForSite($service, $log);
    }

    public function listing(array $params): array
    {
        $rows = [];
        foreach ($this->registry() as $row) {
            if (!empty($params['service']) && $params['service'] !== $row['service']) continue;
            $row['state'] = $this->show($row['unit']);
            $row['execution_user']=($row['state']['User']??'')!==''?$row['state']['User']:'root';
            if (empty($row['managed'])) {
                try { $row['execution'] = $this->existingInfo($row['unit'], $row['service']); }
                catch (OpException $e) { $row['execution'] = ['editable' => false, 'exec_start' => '', 'working_subdir' => '']; }
            }
            $rows[] = $row;
        }
        return ['daemons' => $rows];
    }
    public function candidates(array $params): array
    {
        $out = Proc::must([$this->cfg['systemctl'], 'list-unit-files', '--type=service', '--no-legend', '--no-pager'], null, 15);
        $linked = $this->registry();
        $units = [];
        foreach (explode("\n", trim($out)) as $line) {
            $unit = preg_split('/\s+/', trim($line))[0] ?? '';
            if (\Pjas\Agentd\ComponentPolicy::reservedUnit($this->cfg,$unit)) continue;
            if ($unit === 'pjas-agentd.service' || isset($linked[$unit]) || str_starts_with($unit, 'pjas-daemon-')) continue;
            if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.@-]*\.service\z/D', $unit) && !str_contains($unit, '@.')) $units[] = $unit;
        }
        return ['units' => $units];
    }
    public function save(array $params): array
    {
        return $this->locked(function () use ($params): array {
            $rows = $this->registry();
            $service = $this->site($params['service'] ?? '');
            $label = trim((string) ($params['label'] ?? ''));
            if ($label === '' || strlen($label) > 240 || preg_match('/[\x00-\x1f]/', $label)) throw new OpException('表示名を1〜80文字程度で入力してください');
            if(isset($this->cfg['isolation_site'])&&!empty($params['target_service'])&&$params['target_service']!==$service)throw new OpException('隔離サイト間の常駐処理の移動はできません。移動先で新規登録してください');
            $editing = !empty($params['original_unit']);
            $old = null;
            if ($editing) {
                $unit = $this->unit($params['original_unit']);
                $old = $rows[$unit] ?? null;
                if (!$old || $old['service'] !== $service) throw new OpException('このサイトに関連付けられていません');
                $managed = $old['managed'];
                if (!empty($params['target_service'])) $service = $this->site($params['target_service']);
            } else {
                $managed = ($params['mode'] ?? '') === 'new';
                $unit = $managed ? 'pjas-daemon-' . bin2hex(random_bytes(8)) . '.service' : $this->unit($params['unit'] ?? '');
                if (isset($rows[$unit])) throw new OpException('すでにサイトに関連付けられています');
                if (!$managed && !in_array($unit, $this->candidates([])['units'], true)) throw new OpException('関連付けできる既存サービスではありません');
            }
            if(isset($this->cfg['isolation_site'])&&!$managed)throw new OpException('隔離サイトの常駐処理は専用ユーザーで新規登録してください');
            $row = array_replace($old ?? [], ['unit' => $unit, 'label' => $label, 'service' => $service, 'managed' => $managed, 'command' => '']);
            if (!$managed && (!empty($params['edit_execution']) || (!empty($old['follow_paths']) && $service !== $old['service']))) {
                return $this->saveExisting($row, $params, $old);
            }
            $unitPath = $this->unitDir() . '/' . $unit;
            $scriptPath = $this->dir() . '/' . $unit . '.sh';
            $priorUnit = is_file($unitPath) ? file_get_contents($unitPath) : null;
            $priorScript = is_file($scriptPath) ? file_get_contents($scriptPath) : null;
            if ($managed) {
                $command = trim((string) ($params['command'] ?? ''));
                if ($command === '' || strlen($command) > 4000 || str_contains($command, "\0")) throw new OpException('実行内容を1〜4000バイトで入力してください');
                $base = rtrim($this->cfg['www_root'], '/') . '/' . ($old['service'] ?? $service);
                $command = DaemonDefinition::template($command, $base);
                if(isset($this->cfg['isolation_runtime']))$command=\Pjas\Shared\PhpRuntime::command($command,$this->cfg['isolation_runtime']);
                $row['command'] = $command;
                $row['follow_paths'] = true;
                $row['working_subdir'] = Spec::relPath($params['working_subdir'] ?? ($old['working_subdir'] ?? ''), '作業フォルダ');
                $user = (string) $this->cfg['web_user'];
                $group = (string) $this->cfg['web_group'];
                foreach ([$user, $group] as $v) if (!preg_match('/\A[a-z_][a-z0-9_-]*\z/iD', $v)) throw new OpException('実行ユーザー設定が不正です');
                $current = $this->cfg['www_root'] . '/' . $service . '/current';
                $cwd = $this->workingDirectory($service, $row['working_subdir']);
                // ExecStart の引数は引用する。WorkingDirectory は引用せず、% 指定子だけをエスケープする。
                $quote = static fn(string $p): string => '"' . str_replace(['\\', '"', '%', "\n", "\r"], ['\\\\', '\\"', '%%', '', ''], $p) . '"';
                $body = "# managed by pjas-ctrl\n[Unit]\nDescription=PJAS site worker\nAfter=network.target\n\n[Service]\nType=simple\nUser={$user}\nGroup={$group}\nWorkingDirectory=" . str_replace('%', '%%', $cwd) . "\nExecStart=/bin/bash " . $quote($scriptPath) . "\nRestart=on-failure\nRestartSec=5\nNoNewPrivileges=true\nProtectSystem=full\nProtectHome=true\nPrivateTmp=true\n\n[Install]\nWantedBy=multi-user.target\n";
                try {
                    $this->write($scriptPath, "#!/bin/bash\n" . DaemonDefinition::expand($command, $current) . "\n", 0644);
                    $this->write($unitPath, $body);
                    Proc::must([$this->cfg['systemctl'], 'daemon-reload'], null, 15);
                    $rows[$unit] = $row;
                    $this->write($this->dir() . '/registry.json', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                } catch (\Throwable $e) {
                    if ($priorUnit === null) @unlink($unitPath); else $this->write($unitPath, $priorUnit);
                    if ($priorScript === null) @unlink($scriptPath); else $this->write($scriptPath, $priorScript);
                    Proc::run([$this->cfg['systemctl'], 'daemon-reload'], null, 15);
                    throw $e;
                }
            } else {
                $rows[$unit] = $row;
                $this->write($this->dir() . '/registry.json', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            return $row;
        });
    }
    public function action(array $params): array
    {
        return $this->locked(function () use ($params): array {
            $service = $this->site($params['service'] ?? '');
            $unit = $this->unit($params['unit'] ?? '');
            $row = $this->registry()[$unit] ?? null;
            if (!$row || $row['service'] !== $service) throw new OpException('このサイトに関連付けられていない常駐プログラムは操作できません');
            $action = $params['action'] ?? null;
            if($action==='detach') {
                $rows=$this->registry();unset($rows[$unit]);
                $this->write($this->dir().'/registry.json',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                return ['unit'=>$unit,'action'=>'detach','service_preserved'=>true];
            }
            if (!is_string($action) || !in_array($action, ['start', 'stop', 'restart'], true)) throw new OpException('この操作は許可されていません');
            Proc::must([$this->cfg['systemctl'], $action, '--', $unit], null, 30);
            return ['unit' => $unit, 'action' => $action];
        });
    }
}
