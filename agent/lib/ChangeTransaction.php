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
        $resources = [];
        foreach ($steps as $step) {
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

    private function restore(array $journal): array
    {
        if ($journal['phase'] === 'rolled-back') return $journal;
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
