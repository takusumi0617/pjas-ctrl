#!/usr/bin/env php
<?php
declare(strict_types=1);

/** 一括登録など省略項目のある入力に安全な既定値が入ることを確認する。 */

require dirname(__DIR__) . '/app/Domain/ValidationException.php';
require dirname(__DIR__) . '/app/Domain/Hooks.php';
require dirname(__DIR__) . '/app/Domain/VhostRenderer.php';
require dirname(__DIR__) . '/app/Domain/ServiceRepo.php';

use Pjas\Domain\ServiceRepo;

$service = ServiceRepo::validate([
    'name'    => 'pjas-calc',
    'domain'  => 'calc.example.com',
    'runtime' => 'static',
    'docroot' => '',
], false);

if ($service['allow_override'] !== 'None') {
    fwrite(STDERR, 'FAIL AllowOverride の既定値: ' . var_export($service['allow_override'], true) . "\n");
    exit(1);
}

echo "ok   省略時の AllowOverride は None です\n";
