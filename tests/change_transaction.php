<?php
declare(strict_types=1);

require __DIR__.'/support/features.php';

use Pjas\Agentd\{ChangeTransaction, ControlStore};

[$dir, $cfg] = fixture('change-transaction');
try {
    $store = new ControlStore($cfg);
    $handler = static function (string $operation, string $id) use ($store): array {
        $state = $store->read('fixture-'.$id);
        if ($operation === 'check' && $state) throw new RuntimeException('plan changed');
        if ($operation === 'prepare') {
            $store->write('fixture-'.$id, ['applied'=>true]);
            if ($id === 'second') exit(42); // Exit before the step can report completion.
        }
        if ($operation === 'rollback') {
            $log = $store->read('fixture-order');
            $log[] = $id;
            $store->write('fixture-order', $log);
            $store->write('fixture-'.$id, []);
        }
        return [$id];
    };
    $transaction = new ChangeTransaction($store, ['site'=>$handler, 'batch'=>$handler]);
    $first = ['type'=>'site', 'id'=>'first'];
    rejects(fn()=>$transaction->plan([$first, ['type'=>'batch', 'id'=>'first']]));
    $plan = $transaction->plan([$first, ['type'=>'batch', 'id'=>'second']]);
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('fork failed');
    if ($pid === 0) {
        $transaction->run($plan['id'], false);
        exit(1);
    }
    pcntl_waitpid($pid, $status);
    check(pcntl_wexitstatus($status) === 42, '変更適用中に実行プロセスを中断');
    check($transaction->status($plan['id'])['started'] === 2, '中断したステップの着手記録を保持');
    rejects(fn()=>$transaction->run($plan['id'], false));
    $recovered = $transaction->run($plan['id'], true);
    check($recovered['phase'] === 'rolled-back' && $store->read('fixture-order') === ['second','first'], '別プロセスから逆順に復旧');
    $transaction->run($plan['id'], true);
    check($store->read('fixture-order') === ['second','first'], '復旧済みの変更を再適用しない');
} finally {
    cleanup($dir);
}
