<?php
declare(strict_types=1);
require dirname(__DIR__).'/agent/lib/ComponentDrift.php';

use Pjas\Agentd\ComponentDrift;

$file = tempnam(sys_get_temp_dir(), 'pjas-drift-');
if ($file === false) throw new RuntimeException('検査ファイルを作成できません');
try {
    file_put_contents($file, 'approved');
    $state = ['files' => [$file => hash('sha256', 'approved')]];
    if (ComponentDrift::inspect($state)[0]['status'] !== 'match') throw new RuntimeException('一致');
    file_put_contents($file, 'changed');
    if (ComponentDrift::inspect($state)[0]['status'] !== 'changed') throw new RuntimeException('変更');
    unlink($file);
    if (ComponentDrift::inspect($state)[0]['status'] !== 'missing') throw new RuntimeException('欠落');
    if (ComponentDrift::inspect([]) !== []) throw new RuntimeException('未登録');
    if (DIRECTORY_SEPARATOR === '/') {
        symlink(__FILE__, $file);
        if (ComponentDrift::inspect($state)[0]['status'] !== 'invalid') throw new RuntimeException('リンク');
    }
    echo "component_drift OK\n";
} finally {
    if (is_file($file) || is_link($file)) unlink($file);
}
