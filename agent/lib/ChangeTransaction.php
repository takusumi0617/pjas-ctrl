<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/** Coordinates reversible, replay-safe operations using their own journals. */
final class ChangeTransaction
{
    public function __construct(private ControlStore $store, private array $operations) {}

    public function plan(array $steps): array
    {
        if (!$steps || count($steps) > 100) throw new OpException('変更は1〜100件で指定してください');
        $types = array_column($steps, 'type');
        if (in_array('ctrl', $types, true) && array_diff($types, ['apache','ctrl'])) {
            throw new OpException('進行中のサイト移行がある構成ではctrlを更新できません');
        }
        $resources = [];
        foreach (array_values($steps) as $index => $step) {
            if (($step['type'] === 'ctrl' && $index !== count($steps) - 1)
                || ($step['type'] === 'apache' && array_diff(array_slice($types, $index + 1), ['ctrl']))) {
                throw new OpException('Apache設定変更の後はctrl更新だけを指定できます。ctrl更新は最後に指定してください');
            }
            $handler = $this->handler($step);
            foreach ($handler('check', $step['id']) as $resource) {
                if (isset($resources[$resource])) throw new OpException('変更対象が重複しています: '.$resource);
                $resources[$resource] = true;
            }
        }
        $id = bin2hex(random_bytes(12));
        $journal = ['id'=>$id, 'phase'=>'planned', 'steps'=>array_values($steps), 'started'=>0];
        $this->save($journal);
        return $journal;
    }

    public function status(string $id): array
    {
        if (!preg_match('/\A[a-f0-9]{24}\z/D', $id)) throw new OpException('変更IDが不正です');
        return $this->store->read('change-'.$id) ?: throw new OpException('変更計画がありません');
    }

    public function run(string $id, bool $rollback): array
    {
        $this->status($id);
        $lock = $this->store->lock('change-transaction');
        try {
            $journal = $this->status($id);
            if ($rollback) return $this->restore($journal);
            if ($journal['phase'] === 'prepared') return $journal;
            if ($journal['phase'] !== 'planned') throw new OpException('中断した変更は切り戻してから新しい計画を作成してください');

            foreach ($journal['steps'] as $step) ($this->handler($step))('check', $step['id']);
            foreach ($journal['steps'] as $index => $step) {
                // Record intent before writing. Undo must tolerate a step that never started.
                $journal['phase'] = 'preparing';
                $journal['started'] = $index + 1;
                $this->save($journal);
                ($this->handler($step))('prepare', $step['id']);
            }
            $journal['phase'] = 'prepared';
            $this->save($journal);
            return $journal;
        } finally {
            fclose($lock);
        }
    }

    public function commit(string $id): array
    {
        $lock = $this->store->lock('change-transaction');
        try {
            $journal = $this->status($id);
            if ($journal['phase'] === 'committed') return $journal;
            if (!in_array($journal['phase'], ['prepared','committing'], true)) {
                throw new OpException('準備が完了した変更だけを確定できます');
            }
            $final = array_column($journal['steps'], null, 'type');
            if (!isset($final['apache']) && !isset($final['ctrl'])) throw new OpException('確定対象がありません');
            if ($journal['phase'] === 'prepared') {
                foreach (['apache','ctrl'] as $type) {
                    if (isset($final[$type])) ($this->handler($final[$type]))('check-commit', $final[$type]['id']);
                }
                $journal['phase'] = 'committing';
                $this->save($journal);
            }
            if (isset($final['ctrl'])) {
                $step = $final['ctrl'];
                $result = ($this->handler($step))('commit', $step['id']);
                if ($result !== ['succeeded']) return $journal + ['control_update'=>$result[0]];
            }
            if (isset($final['apache'])) {
                $step = $final['apache'];
                ($this->handler($step))('commit', $step['id']);
            }
            $journal['phase'] = 'committed';
            $this->save($journal);
            return $journal;
        } finally {
            fclose($lock);
        }
    }

    private function restore(array $journal): array
    {
        if ($journal['phase'] === 'rolled-back') return $journal;
        $steps = array_column($journal['steps'], null, 'type');
        if ($journal['phase'] === 'committed' || ($journal['phase'] === 'committing' && !isset($steps['ctrl']))) {
            throw new OpException('確定を開始した変更は一括切り戻しできません');
        }
        // Check all touched operations before restoring any.
        foreach (array_slice($journal['steps'], 0, $journal['started']) as $step) {
            ($this->handler($step))('check-rollback', $step['id']);
        }
        $journal['phase'] = 'rolling-back';
        $this->save($journal);
        while ($journal['started'] > 0) {
            $step = $journal['steps'][$journal['started'] - 1];
            ($this->handler($step))('rollback', $step['id']);
            --$journal['started'];
            $this->save($journal);
        }
        $journal['phase'] = 'rolled-back';
        $this->save($journal);
        return $journal;
    }

    private function handler(array $step): callable
    {
        return $this->operations[$step['type']] ?? throw new OpException('未対応の変更種別です');
    }

    private function save(array $journal): void
    {
        $this->store->write('change-'.$journal['id'], $journal);
    }
}
