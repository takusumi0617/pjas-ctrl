<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/**
 * 外部コマンド実行。必ず argv 配列で渡し、シェルを経由させない
 * (proc_open は配列を渡すと execvp 相当で実行する)。
 */
final class Proc
{
    /** @param array<int,string> $argv @return array{code:int,out:string,err:string} */
    public static function run(array $argv, ?string $cwd = null, int $timeout = 120, ?string $user = null, array $env = [], ?string $stdinFile = null, ?string $stdoutFile = null): array
    {
        // agent の SIGCHLD ハンドラが proc_open の子まで先に回収すると、成功時でも
        // exitcode=-1 になる。実行中だけ通知を保留し、proc_get_status で確実に回収する。
        $oldMask = [];
        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $oldMask);
        try {
        if ($user !== null && (posix_getpwnam($user)['uid']??-1)!==posix_geteuid()) {
            // 非特権ユーザーで実行する (フックなど)
            $argv = array_merge(['/usr/bin/setpriv', '--reuid', $user, '--regid', $user, '--init-groups', '--'], $argv);
        }

        $desc = [0 => ['file', $stdinFile ?? '/dev/null', 'r'], 1 => $stdoutFile===null ? ['pipe', 'w'] : ['file',$stdoutFile,'w'], 2 => ['pipe', 'w']];
        $p = proc_open($argv, $desc, $pipes, $cwd, $env ?: null);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => '', 'err' => 'proc_open に失敗: ' . ($argv[0] ?? '')];
        }
        if(isset($pipes[1]))stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $out .= (isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '');
            $err .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '');
                $err .= (string) stream_get_contents($pipes[2]);
                if(isset($pipes[1]))fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return ['code' => (int) $st['exitcode'], 'out' => $out, 'err' => $err];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p, 9);
                if(isset($pipes[1]))fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return ['code' => 124, 'out' => $out, 'err' => $err . "\n(timeout {$timeout}s)"];
            }
            usleep(50_000);
        }
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $oldMask);
        }
    }

    /** 失敗したら例外。 */
    public static function must(array $argv, ?string $cwd = null, int $timeout = 120, ?string $user = null): string
    {
        $r = self::run($argv, $cwd, $timeout, $user);
        if ($r['code'] !== 0) {
            throw new OpException(implode(' ', $argv) . " が失敗 (exit {$r['code']}): " . trim($r['err'] ?: $r['out']));
        }
        return $r['out'];
    }
}
