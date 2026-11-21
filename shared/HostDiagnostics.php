<?php
declare(strict_types=1);

namespace Pjas\Shared;

final class HostDiagnostics
{
    public function __construct(
        private \Closure $run,
        private \Closure $emit,
        private \Closure $fileExists
    ) {}

    public function inspect(string $root, string $version, ?string $component): int
    {
        $failed = false;
        $report = function (string $status, string $label, string $detail) use (&$failed): void {
            $failed = $failed || $status === 'FAIL';
            ($this->emit)("$status $label — $detail");
        };

        $revision = ($this->run)(['/usr/bin/git', '--no-optional-locks', 'rev-parse', 'HEAD'], $root, 5);
        $hash = trim($revision['out']);
        if ($revision['code'] === 0 && preg_match('/\A[0-9a-f]{40,64}\z/', $hash)) {
            $report('PASS', '配備コミット', $hash);
        } else {
            $report('WARN', '配備コミット', 'HEADを特定できません');
        }

        $runtime = 'php'.$version.'-fpm';
        $report('PASS', '管理用PHP設定', $version);
        $units = ['pjas-agentd.service', 'pjas-control-web.service', $runtime.'.service', 'pjas-monitor.timer'];
        foreach ($units as $unit) {
            $result = ($this->run)([
                '/usr/bin/systemctl', 'show', '--property=LoadState,ActiveState,UnitFileState', '--', $unit,
            ], null, 5);
            $properties = [];
            foreach (explode("\n", trim($result['out'])) as $line) {
                $pair = explode('=', $line, 2);
                if (count($pair) === 2) {
                    $properties[$pair[0]] = $pair[1];
                }
            }
            $active = $properties['ActiveState'] ?? 'unknown';
            $loaded = $properties['LoadState'] ?? 'unknown';
            $fileState = $properties['UnitFileState'] ?? 'unknown';
            if ($result['code'] !== 0 || $loaded !== 'loaded') {
                $report('FAIL', $unit, 'ユニットを確認できません (LoadState='.$loaded.')');
            } elseif ($active === 'active') {
                $report('PASS', $unit, 'active');
            } elseif ($unit === 'pjas-monitor.timer' && $active === 'inactive' && $fileState === 'disabled') {
                $report('WARN', $unit, '無効化され停止中です');
            } else {
                $report('FAIL', $unit, 'ActiveState='.$active);
            }
        }

        $http = ($this->run)([
            '/usr/bin/curl', '--disable', '--silent', '--show-error', '--noproxy', '*',
            '--max-time', '10', '--output', '/dev/null', '--write-out', '%{http_code}',
            'http://127.0.0.1:8184/login',
        ], null, 12);
        $status = trim($http['out']);
        if ($http['code'] === 0 && $status === '200') {
            $report('PASS', '管理用HTTP', '/login HTTP 200');
        } else {
            $detail = $http['code'] === 0 && preg_match('/\A[0-9]{3}\z/', $status)
                ? 'HTTP '.$status : '接続検査に失敗しました (終了コード '.$http['code'].')';
            $report('FAIL', '管理用HTTP', $detail);
        }

        $checks = ['pjas-sites-check' => []];
        if ($component !== null) {
            $checks['pjas-components-check'] = [$component, '--running-only'];
        }
        foreach ($checks as $check => $args) {
            $path = $root.'/bin/'.$check;
            if (!(($this->fileExists)($path))) {
                $report('SKIP', $check, '検査CLIがありません');
                continue;
            }
            $result = ($this->run)([PhpRuntime::cli($runtime), $path, ...$args], $root, 120);
            foreach ([$result['out'], $result['err']] as $output) {
                if (trim($output) !== '') {
                    ($this->emit)(trim($output));
                }
            }
            $code = $result['code'];
            $state = match ($code) {
                0 => 'PASS',
                75, 77 => 'SKIP',
                default => 'FAIL',
            };
            $report($state, $check, '終了コード '.$code);
        }

        return $failed ? 1 : 0;
    }
}
