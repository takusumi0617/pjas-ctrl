<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/** ジョブの進捗ログと状態を job_dir に書く。web はこれを読んで表示する。 */
final class JobLog
{
    private string $logFile;
    private string $stateFile;
    private array  $state;

    public function __construct(private int $id, string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->logFile   = "{$dir}/{$id}.log";
        $this->stateFile = "{$dir}/{$id}.json";
        $this->state     = ['state' => 'running', 'detail' => '', 'started_at' => date('Y-m-d H:i:s')];
        $this->flush();
        // web (別ユーザー) が読めるように
        @chmod($this->logFile, 0644);
        @chmod($this->stateFile, 0644);
    }

    public function line(string $msg): void
    {
        file_put_contents($this->logFile, '[' . date('H:i:s') . "] {$msg}\n", FILE_APPEND);
    }

    /** コマンド実行結果をログに落とす */
    public function proc(string $label, array $r): void
    {
        $this->line($label . ($r['code'] === 0 ? ' → ok' : " → exit {$r['code']}"));
        foreach (preg_split('/\R/', trim($r['out'] . "\n" . $r['err'])) ?: [] as $l) {
            if (trim($l) !== '') {
                $this->line('    ' . $l);
            }
        }
    }

    public function set(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
        $this->flush();
    }

    public function finish(string $state, string $detail = ''): void
    {
        $this->state['state']       = $state;
        $this->state['detail']      = $detail;
        $this->state['finished_at'] = date('Y-m-d H:i:s');
        $this->flush();
        $this->line($state === 'succeeded' ? "✔ 完了: {$detail}" : "✖ 失敗: {$detail}");
    }

    private function flush(): void
    {
        file_put_contents($this->stateFile, json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($this->stateFile, 0644);
    }
}
