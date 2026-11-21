<?php
declare(strict_types=1);

namespace Pjas\Domain;

/** Preserve individual state changes and send one summary per direction per tick. */
final class HealthNotifications
{
    private array $changes = [];

    public function check(string $key, ?int $site, bool $ok, string $message, bool $notify = true): void
    {
        if (Alerting::check($key, $site, $ok, $message, false) && $notify) {
            $this->changes[(int)$ok][] = $message;
        }
    }

    public function send(): void
    {
        foreach ($this->changes as $ok => $messages) {
            $count = count($messages);
            $summary = 'サービス・サイトの状態変化 '.$count.'件';
            foreach (array_slice($messages, 0, 5) as $message) {
                $summary .= "\n・".mb_substr($message, 0, 130);
            }
            if ($count > 5) $summary .= "\n残り ".($count-5).' 件は管理画面で確認できます。';
            Alerting::event(null, 'health.summary', $summary, (bool)$ok);
        }
        $this->changes = [];
    }
}
