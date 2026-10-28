<?php
declare(strict_types=1);
namespace Pjas\Agentd;

/** 既存ユニットの起動設定を読み、サイトパスだけを可変にする。 */
final class DaemonDefinition
{
    public static function parse(string $text, string $webUser): array
    {
        $text = preg_replace('/\\\\\r?\n[ \t]*/', ' ', $text);
        $section = '';
        $values = [];
        $commands = [];
        $pathSettings = [];
        $listKeys = ['EnvironmentFile', 'ExecStartPre', 'ExecStartPost', 'ExecStop', 'ExecStopPost', 'ReadWritePaths', 'ReadOnlyPaths'];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
            if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) { $section = $m[1]; continue; }
            if ($section !== 'Service' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key); $value = trim($value);
            $values[$key] = $value;
            if (in_array($key, $listKeys, true)) {
                if ($value === '') $pathSettings[$key] = [];
                else $pathSettings[$key][] = $value;
            }
            if ($key === 'PIDFile') $pathSettings[$key] = $value === '' ? [] : [$value];
            if ($key === 'ExecStart') { if ($value === '') $commands = []; else $commands[] = $value; }
        }
        $type = $values['Type'] ?? 'simple';
        $user = $values['User'] ?? '';
        $editable = $user === $webUser && in_array($type, ['simple', 'exec'], true) && count($commands) === 1;
        $command = count($commands) === 1 ? $commands[0] : '';
        if (!preg_match('~^/[^\s]+(?:\s|$)|^"/[^"\r\n]+"(?:\s|$)~', $command)) $editable = false;
        return ['exec_start' => $command, 'working_directory' => trim($values['WorkingDirectory'] ?? '', '"'),
            'editable' => $editable, 'user' => $user, 'type' => $type, 'path_settings' => $pathSettings];
    }
    public static function template(string $text, string $base): string
    {
        // shared はリリースをまたいで固定。current や日時付きリリースは共通記法にする。
        $base = rtrim($base, '/');
        $pattern = '~' . preg_quote($base, '~') . '(?:(/shared)|/(?:current|releases/[0-9]{8}-[0-9]{6}))?(?=/|[\s"\x27]|$)~';
        return preg_replace_callback($pattern, static fn($m) => !empty($m[1]) ? $base . '/shared' : '{{site_root}}', $text);
    }

    public static function expand(string $text, string $current): string
    {
        return str_replace('{{site_root}}', $current, $text);
    }
    public static function command(string $command): string
    {
        if ($command === '' || strlen($command) > 4000 || preg_match('/[\x00-\x1f\x7f]/', $command)
            || !preg_match('~^/[^\s]+(?:\s|$)|^"/[^"\r\n]+"(?:\s|$)~', $command)) {
            throw new OpException('起動コマンドは絶対パスから始まる1行で指定してください（例: /usr/bin/php8.4 websocket/server.php start）');
        }
        // 実行権限を変更する systemd の + / ! 等の接頭辞は絶対パスの検証で拒否する。
        return $command;
    }
}
