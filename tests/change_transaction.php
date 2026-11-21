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
    $updatePhase = 'queued';
    $events = [];
    $apache = static function (string $operation) use (&$events): array {
        $events[] = 'apache:'.$operation;
        return ['test-vhost'];
    };
    $ctrl = static function (string $operation) use (&$updatePhase, &$events): array {
        $events[] = 'ctrl:'.$operation;
        if ($operation === 'check-rollback' && $updatePhase !== 'failed') throw new RuntimeException('update is not restored');
        return $operation === 'commit' ? [$updatePhase] : [':ctrl-update'];
    };
    $combined = new ChangeTransaction($store, ['site'=>$handler,'apache'=>$apache,'ctrl'=>$ctrl]);
    $pair = [['type'=>'apache','id'=>'vhost'],['type'=>'ctrl','id'=>'review']];
    rejects(fn()=>$combined->plan([$first, ...$pair]));
    $plan = $combined->plan($pair);
    $combined->run($plan['id'], false);
    $pending = $combined->commit($plan['id']);
    check($pending['control_update']==='queued' && !in_array('apache:commit',$events,true), 'ctrl更新の完了までApacheの復旧記録を保持する');
    rejects(fn()=>$combined->run($plan['id'], true));
    $updatePhase = 'failed';
    $combined->commit($plan['id']);
    check($combined->run($plan['id'], true)['phase']==='rolled-back' && end($events)==='apache:rollback',
        'ctrlの復旧後にApacheを切り戻せる');
    $plan = $combined->plan($pair);
    $combined->run($plan['id'], false);
    $updatePhase = 'succeeded';
    check($combined->commit($plan['id'])['phase']==='committed' && end($events)==='apache:commit',
        'ctrl更新に成功してからApacheを確定する');
} finally {
    cleanup($dir);
}
