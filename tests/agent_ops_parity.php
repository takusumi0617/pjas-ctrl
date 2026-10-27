#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Web 側の許可操作と agent 側の登録操作が一致することを確認する。 */

require dirname(__DIR__) . '/app/Agent/AgentClient.php';

use Pjas\Agent\AgentClient;

$source = (string) file_get_contents(dirname(__DIR__) . '/agent/pjas-agentd.php');
preg_match_all("/^    '([a-z.]+)'\s*=>/m", $source, $matches);

$client = AgentClient::OPS;
$agent  = array_values(array_unique($matches[1] ?? []));
sort($client);
sort($agent);

$missingFromClient = array_values(array_diff($agent, $client));
$missingFromAgent  = array_values(array_diff($client, $agent));

if ($missingFromClient || $missingFromAgent) {
    foreach ($missingFromClient as $op) {
        fwrite(STDERR, "FAIL Web 側の許可リストにありません: {$op}\n");
    }
    foreach ($missingFromAgent as $op) {
        fwrite(STDERR, "FAIL agent 側の登録表にありません: {$op}\n");
    }
    exit(1);
}

echo 'ok   Web と agent の許可操作が一致しています (' . count($client) . " 件)\n";
