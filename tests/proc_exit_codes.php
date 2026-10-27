#!/usr/bin/env php
<?php
declare(strict_types=1);

/** SIGCHLD回収処理が動いていても外部コマンドの終了コードを失わないことを確認する。 */

require dirname(__DIR__) . '/agent/lib/OpException.php';
require dirname(__DIR__) . '/agent/lib/Proc.php';

use Pjas\Agentd\Proc;

pcntl_async_signals(true);
pcntl_signal(SIGCHLD, static function (): void {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
    }
});

$fail = 0;
for ($i = 0; $i < 20; $i++) {
    $ok = Proc::run(['/bin/true']);
    $ng = Proc::run(['/bin/false']);
    if ($ok['code'] !== 0 || $ng['code'] !== 1) {
        $fail++;
        echo "FAIL {$i}: true={$ok['code']} false={$ng['code']}\n";
    }
}

pcntl_signal(SIGCHLD, SIG_DFL);
if ($fail === 0) {
    echo "ok   SIGCHLD回収中も終了コードを正しく取得しました (20回)\n";
}
exit($fail === 0 ? 0 : 1);
