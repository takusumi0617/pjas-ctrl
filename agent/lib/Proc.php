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
    public static function run(array $argv, ?string $cwd = null, int $timeout = 120, ?string $user = null, array $env = []): array
    {
        if ($user !== null) {
            // 非特権ユーザーで実行する (フックなど)
            $argv = array_merge(['/usr/bin/setpriv', '--reuid', $user, '--regid', $user, '--init-groups', '--'], $argv);
        }

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open($argv, $desc, $pipes, $cwd, $env ?: null);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => '', 'err' => 'proc_open に失敗: ' . ($argv[0] ?? '')];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return ['code' => (int) $st['exitcode'], 'out' => $out, 'err' => $err];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return ['code' => 124, 'out' => $out, 'err' => $err . "\n(timeout {$timeout}s)"];
            }
            usleep(50_000);
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
