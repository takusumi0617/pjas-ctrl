#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * web (プレビュー) と agent (実書き出し) の vhost 生成結果が一致することを確認する。
 * ずれると「画面で承認した内容と実際に書かれる設定が違う」ことになるため、CI で必ず回す。
 *
 *   php tests/vhost_parity.php
 */

define('PJAS_ROOT', dirname(__DIR__));

require PJAS_ROOT . '/app/Domain/VhostRenderer.php';
require PJAS_ROOT . '/agent/lib/OpException.php';
require PJAS_ROOT . '/agent/lib/Spec.php';
require PJAS_ROOT . '/agent/lib/Paths.php';
require PJAS_ROOT . '/agent/lib/Proc.php';
require PJAS_ROOT . '/agent/ops/Vhost.php';

use Pjas\Agentd\Ops\Vhost as AgentVhost;
use Pjas\Agentd\Paths;
use Pjas\Agentd\Spec;
use Pjas\Domain\VhostRenderer;

$cases = [
    ['name' => 'billing', 'domain' => 'billing.example.com', 'runtime' => 'php8.5-fpm',
     'docroot' => 'public', 'allow_override' => 'FileInfo', 'extra_conf' => 'Header always set X-Frame-Options DENY',
     'tls' => 1],
    ['name' => 'docs', 'domain' => 'docs.example.com', 'runtime' => 'static',
     'docroot' => '', 'allow_override' => 'None', 'extra_conf' => '', 'tls' => 0],
    ['name' => 'auth', 'domain' => 'auth.example.com', 'runtime' => 'php8.1-fpm',
     'docroot' => 'web/public', 'allow_override' => 'All',
     'extra_conf' => "RewriteEngine On\nErrorDocument 404 /404.html", 'tls' => 1],
];

$chatExtra = file_get_contents(__DIR__.'/support/legacy_chat.conf');
$cases[] = ['name' => 'pjas-chat', 'domain' => 'chat.example.com', 'runtime' => 'php8.5-fpm',
    'docroot' => 'dist', 'allow_override' => 'None', 'extra_conf' => $chatExtra, 'tls' => 1];

$cases[] = ['name'=>'php85','domain'=>'php85.example.com','runtime'=>'php8.5-fpm','docroot'=>'public','allow_override'=>'None','extra_conf'=>'','tls'=>1];

$cfg   = ['apache_sites' => '/etc/apache2/sites-available'];
$paths = new Paths('/var/www');
$agent = new AgentVhost($cfg, $paths);

$fail = 0;
foreach ($cases as $c) {
    $web = VhostRenderer::render($c, '/var/www');
    $agt = $agent->render(Spec::service($c + ['shared' => [], 'hooks' => []]));

    if ($web === $agt) {
        echo "ok   {$c['name']}\n";
        continue;
    }
    $fail++;
    echo "FAIL {$c['name']}\n";
    foreach (VhostRenderer::diff($web, $agt) as [$sign, $line]) {
        if ($sign !== ' ') {
            echo "  {$sign} {$line}\n";
        }
    }
}

$chat = VhostRenderer::render($cases[3]);
foreach (['DocumentRoot /var/www/pjas-chat/current/dist', '<Directory /var/www/pjas-chat/current/dist>',
    'fcgi://localhost/var/www/pjas-chat/current/api/index.php', 'ProxyPass /ws ws://127.0.0.1:8081',
    'FallbackResource /index.html'] as $expected) {
    if (!str_contains($chat, $expected)) { $fail++; echo "FAIL chat設定: {$expected}\n"; }
}
if (str_contains($chat, '{{') || VhostRenderer::rejectedDirectives($chatExtra)) {
    $fail++; echo "FAIL chat設定の検証または置き換え\n";
}
foreach (['</Directory>', '<Directory {{document_root}}>',
    "<Directory {{document_root}}>\n<Directory {{document_root}}>\n</Directory>\n</Directory>",
    "<Directory {{document_root}}>\nInclude /etc/passwd\n</Directory>",
    '<VirtualHost *:443>', "Header set Test ok\0"] as $bad) {
    if (!VhostRenderer::rejectedDirectives($bad)) { $fail++; echo "FAIL Web検証が不正設定を許可\n"; }
    try { Spec::extraConf($bad); $fail++; echo "FAIL agent検証が不正設定を許可\n"; }
    catch (\Pjas\Agentd\OpException) {}
}

$tlsVhost = VhostRenderer::render($cases[0], '/var/www');
foreach ([VhostRenderer::DEFAULT_TLS_CERT_FILE, VhostRenderer::DEFAULT_TLS_KEY_FILE] as $path) {
    if (!str_contains($tlsVhost, $path)) {
        $fail++;
        echo "FAIL Cloudflare 証明書パスがありません: {$path}\n";
    } else {
        echo "ok   Cloudflare 証明書: {$path}\n";
    }
}
if (str_contains($tlsVhost, '/etc/letsencrypt/')) {
    $fail++;
    echo "FAIL Let's Encrypt の証明書パスが残っています\n";
}

// 追加ディレクティブの許可リストが両者で一致していること
if (VhostRenderer::ALLOWED_DIRECTIVES !== Spec::ALLOWED_DIRECTIVES) {
    $fail++;
    echo "FAIL 許可ディレクティブ一覧が web と agent で一致していません\n";
}

// 危険な追加ディレクティブが agent 側で拒否されること
foreach (['<Directory />', 'Include /etc/passwd', 'LoadModule foo modules/x.so', 'ScriptAlias /x /bin'] as $bad) {
    try {
        Spec::extraConf($bad);
        $fail++;
        echo "FAIL 拒否されるべき指定が通りました: {$bad}\n";
    } catch (\Pjas\Agentd\OpException) {
        echo "ok   拒否: {$bad}\n";
    }
}

// フック許可リスト
foreach (['composer install --no-dev -o' => true,
          'rm -rf /'                     => false,
          'composer install; rm -rf /'   => false,
          'npm run build'                => true] as $cmd => $expect) {
    $got = true;
    try {
        Spec::hook((string) $cmd);
    } catch (\Pjas\Agentd\OpException) {
        $got = false;
    }
    if ($got !== $expect) {
        $fail++;
        echo "FAIL フック判定: {$cmd}\n";
    } else {
        echo 'ok   フック' . ($expect ? '許可' : '拒否') . ": {$cmd}\n";
    }
}

echo $fail === 0 ? "\nすべて一致しました\n" : "\n{$fail} 件の不一致\n";
exit($fail === 0 ? 0 : 1);
