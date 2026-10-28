<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;

use Pjas\Agentd\OpException;
use Pjas\Agentd\Proc;

final class Daemon
{
    public function __construct(private array $cfg) {}

    private function units(): array
    {
        $units = (array) ($this->cfg['managed_daemons'] ?? []);
        foreach ($units as $unit => $label) {
            if (!is_string($unit) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.@-]*\.service\z/D', $unit)
                || $unit === 'pjas-agentd.service' || !is_string($label)) {
                throw new OpException('常駐プログラムの管理対象設定が不正です');
            }
        }
        return $units;
    }

    public function listing(array $params): array
    {
        $rows = [];
        foreach ($this->units() as $unit => $label) {
            $out = Proc::must([$this->cfg['systemctl'], 'show', '--no-pager',
                '--property=LoadState,ActiveState,SubState,UnitFileState', '--', $unit], null, 10);
            $state = [];
            foreach (explode("\n", trim($out)) as $line) {
                if (str_contains($line, '=')) {
                    [$k, $v] = explode('=', $line, 2);
                    $state[$k] = $v;
                }
            }
            $rows[] = ['unit' => $unit, 'label' => $label, 'state' => $state];
        }
        return ['daemons' => $rows];
    }

    public function action(array $params): array
    {
        $unit = $params['unit'] ?? null;
        $action = $params['action'] ?? null;
        if (!is_string($unit) || !array_key_exists($unit, $this->units())) {
            throw new OpException('管理対象に登録されていない常駐プログラムです');
        }
        if (!is_string($action) || !in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new OpException('この操作は許可されていません');
        }
        Proc::must([$this->cfg['systemctl'], $action, '--', $unit], null, 30);
        return ['unit' => $unit, 'action' => $action];
    }
}
