<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Proc;
use Pjas\Agentd\Spec;

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
            '--property=LoadState,ActiveState,SubState,UnitFileState', '--', $unit], null, 10);
        $state = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (str_contains($line, '=')) { [$k, $v] = explode('=', $line, 2); $state[$k] = $v; }
        }
        return $state;
    }
    public function listing(array $params): array
    {
        $rows = [];
        foreach ($this->registry() as $row) {
            if (!empty($params['service']) && $params['service'] !== $row['service']) continue;
            $row['state'] = $this->show($row['unit']);
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
            $row = ['unit' => $unit, 'label' => $label, 'service' => $service, 'managed' => $managed, 'command' => ''];
            $unitPath = $this->unitDir() . '/' . $unit;
            $scriptPath = $this->dir() . '/' . $unit . '.sh';
            $priorUnit = is_file($unitPath) ? file_get_contents($unitPath) : null;
            $priorScript = is_file($scriptPath) ? file_get_contents($scriptPath) : null;
            if ($managed) {
                $command = trim((string) ($params['command'] ?? ''));
                if ($command === '' || strlen($command) > 4000 || str_contains($command, "\0")) throw new OpException('実行内容を1〜4000バイトで入力してください');
                $row['command'] = $command;
                $user = (string) $this->cfg['web_user'];
                $group = (string) $this->cfg['web_group'];
                foreach ([$user, $group] as $v) if (!preg_match('/\A[a-z_][a-z0-9_-]*\z/iD', $v)) throw new OpException('実行ユーザー設定が不正です');
                $cwd = $this->cfg['www_root'] . '/' . $service . '/current';
                // パスは systemd の引数として引用し、% 指定子の展開を防ぐ。
                $quote = static fn(string $p): string => '"' . str_replace(['\\', '"', '%', "\n", "\r"], ['\\\\', '\\"', '%%', '', ''], $p) . '"';
                $body = "# managed by pjas-ctrl\n[Unit]\nDescription=PJAS site worker\nAfter=network.target\n\n[Service]\nType=simple\nUser={$user}\nGroup={$group}\nWorkingDirectory=" . $quote($cwd) . "\nExecStart=/bin/bash " . $quote($scriptPath) . "\nRestart=on-failure\nRestartSec=5\nNoNewPrivileges=true\nProtectSystem=full\nProtectHome=true\nPrivateTmp=true\n\n[Install]\nWantedBy=multi-user.target\n";
                try {
                    $this->write($scriptPath, "#!/bin/bash\n" . $command . "\n", 0644);
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
            if (!is_string($action) || !in_array($action, ['start', 'stop', 'restart'], true)) throw new OpException('この操作は許可されていません');
            Proc::must([$this->cfg['systemctl'], $action, '--', $unit], null, 30);
            return ['unit' => $unit, 'action' => $action];
        });
    }
}
