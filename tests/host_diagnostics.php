<?php
declare(strict_types=1);

use Pjas\Shared\HostDiagnostics;

require dirname(__DIR__).'/shared/PhpRuntime.php';
require dirname(__DIR__).'/shared/HostDiagnostics.php';

function scenario(array $options, int $exit, array $expected): void
{
    $messages = [];
    $diagnostics = new HostDiagnostics(
        static function (array $argv) use ($options): array {
            $command = basename($argv[0]);
            $result = match ($command) {
                'git' => [0, str_repeat('a', 40)],
                'curl' => [0, $options['http'] ?? '200'],
                'systemctl' => [0, match (end($argv)) {
                    'pjas-agentd.service' => "LoadState=loaded\nActiveState=".($options['agent'] ?? 'active')."\n",
                    'pjas-monitor.timer' => "LoadState=loaded\n".($options['timer'] ?? "ActiveState=active\n"),
                    'pjas-control-web.service', 'php8.5-fpm.service' => "LoadState=loaded\nActiveState=active\n",
                    default => throw new RuntimeException('予期しないユニット'),
                }],
                'php8.5' => match (basename($argv[1])) {
                    'pjas-sites-check' => [0, '通常サイトの検査結果'],
                    'pjas-components-check' => array_slice($argv, 2) === ['pjas-cert', '--running-only']
                        ? [$options['componentCode'] ?? 0, 'コンポーネントの検査結果']
                        : throw new RuntimeException('コンポーネント名が違います'),
                    default => throw new RuntimeException('予期しない検査CLI'),
                },
                default => throw new RuntimeException('予期しないコマンド'),
            };
            return ['code' => $result[0], 'out' => $result[1], 'err' => ''];
        },
        static function (string $line) use (&$messages): void { $messages[] = $line; },
        static fn(string $path): bool => basename($path) !== ($options['missing'] ?? '')
    );
    $actual = $diagnostics->inspect('/opt/pjas-ctrl', '8.5', $options['component'] ?? null);
    $output = implode("\n", $messages);
    if ($actual !== $exit) {
        throw new RuntimeException('終了コードが違います: '.$output);
    }
    foreach ($expected as $fragment) {
        if (!str_contains($output, $fragment)) {
            throw new RuntimeException('診断結果がありません: '.$fragment);
        }
    }
}

scenario([], 0, ['PASS 管理用HTTP', 'PASS pjas-sites-check']);
scenario(['http' => '503', 'agent' => 'failed'], 1, ['FAIL 管理用HTTP', 'HTTP 503', 'FAIL pjas-agentd.service', 'PASS pjas-sites-check']);
scenario(['missing' => 'pjas-sites-check', 'timer' => "ActiveState=inactive\nUnitFileState=disabled\n"], 0, ['SKIP pjas-sites-check', 'WARN pjas-monitor.timer']);
scenario(['component' => 'pjas-cert', 'componentCode' => 77], 0, ['SKIP pjas-components-check']);
scenario(['component' => 'pjas-cert', 'componentCode' => 1], 1, ['FAIL pjas-components-check']);
echo "host_diagnostics OK\n";
