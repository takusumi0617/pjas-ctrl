<?php
declare(strict_types=1);

namespace Pjas\Agentd;

/** Host-local recovery for an interrupted standard VirtualHost replacement. */
final class ApacheRecovery
{
    private const KEY = 'apache-change-pending';

    public function __construct(private ControlStore $store) {}

    public function assertIdle(): void
    {
        if ($this->store->read(self::KEY)) throw new OpException('未完了のApache設定変更があります。pjas-apache-recover statusで確認してください');
    }

    public static function capture(string $path): array
    {
        clearstatcache(true, $path);
        if (is_link($path)) return ['kind'=>'link', 'target'=>readlink($path)];
        if (!file_exists($path)) return ['kind'=>'absent'];
        if (!is_file($path)) throw new OpException('Apache設定のファイル種別が不正です');
        $body = file_get_contents($path);
        if ($body === false) throw new OpException('Apache設定の退避に失敗しました');
        return ['kind'=>'file', 'body'=>base64_encode($body), 'mode'=>fileperms($path)&07777,
            'uid'=>fileowner($path), 'gid'=>filegroup($path)];
    }

    /** Each entry supplies the only intermediate states this operation may create. */
    public function begin(string $site, array $changes): void
    {
        $this->assertIdle();
        $entries = [];
        foreach ($changes as $path => $expected) {
            $entries[$path] = ['before'=>self::capture($path), 'expected'=>$expected];
        }
        $this->store->write(self::KEY, ['site'=>$site, 'time'=>gmdate('c'), 'entries'=>$entries]);
    }

    public function status(): array
    {
        $state = $this->store->read(self::KEY);
        if (!$state) return ['phase'=>'idle'];
        return ['phase'=>'pending', 'site'=>$state['site'], 'time'=>$state['time'], 'paths'=>array_keys($state['entries'])];
    }

    public function complete(): void
    {
        $this->store->write(self::KEY, []);
    }

    public function rollback(callable $reload): void
    {
        $state = $this->store->read(self::KEY);
        if (!$state) return;
        foreach ($state['entries'] as $path => $entry) {
            $current = self::capture($path);
            if ($current === $entry['before']) continue;
            $candidate = $entry['expected'];
            if ($candidate['kind'] === 'link') {
                $matches = $current['kind'] === 'link' && realpath($path) === $candidate['resolved'];
            } else {
                $matches = array_intersect_key($current, $candidate) === $candidate;
            }
            if (!$matches) throw new OpException('別の変更を検出しました。復旧前に確認してください: '.$path);
        }
        foreach (array_reverse($state['entries'], true) as $path => $entry) {
            $before = $entry['before'];
            if (self::capture($path) === $before) continue;
            if ($before['kind'] === 'absent') {
                if (!unlink($path)) throw new OpException('変更後の設定を削除できません: '.$path);
                continue;
            }
            $temp = $path.'.pjas-recover-'.bin2hex(random_bytes(8));
            try {
                if ($before['kind'] === 'link') {
                    if (!symlink($before['target'], $temp)) throw new OpException('有効化リンクを復元できません');
                } elseif (file_put_contents($temp, base64_decode($before['body'], true)) === false
                    || !chown($temp, $before['uid']) || !chgrp($temp, $before['gid']) || !chmod($temp, $before['mode'])) {
                    throw new OpException('Apache設定を復元できません');
                }
                if (!rename($temp, $path)) throw new OpException('Apache設定を配置できません');
            } finally {
                if (file_exists($temp) || is_link($temp)) unlink($temp);
            }
        }
        $reload();
        $this->complete();
    }
}
