<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/** A final update step delegates code and database recovery to the standalone updater. */
final class ControlUpdateChange
{
    public function __construct(private ControlStore $store, private ControlUpdate $update) {}

    public function __invoke(string $operation, string $id): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new OpException('ctrl更新の確認IDが不正です');
        $job = $this->store->read('control-update-job-'.$id);
        $state = $this->store->read('control-update');
        if (in_array($operation, ['check','prepare','check-commit'], true)) {
            $review = $this->store->read('control-update-review-'.$id);
            if ($job || !$review || ($review['expires_at']??0)<time()
                || $review['blockers'] || $review['base']===$review['target']) {
                throw new OpException('有効なctrl更新の確認IDを指定してください');
            }
            foreach (glob($this->store->root().'/upgrade-active-*.json') ?: [] as $file) {
                if ($this->store->read(basename($file,'.json'))) throw new OpException('サイト移行を完了してからctrlを更新してください');
            }
        }
        if ($operation === 'commit') {
            $result = $this->update->start(['review'=>$id, 'confirm'=>true], true);
            return [$result['phase']];
        }
        if ($operation === 'check-rollback' && $job
            && (($state['id']??null)!==$id || ($state['phase']??null)!=='failed' || empty($state['rollback_complete']))) {
            throw new OpException('ctrl更新が終了していないか復旧が未完了です。更新状態を確認してください');
        }
        return [':ctrl-update'];
    }
}
