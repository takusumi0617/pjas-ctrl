<?php
use Pjas\Agent\AgentClient;
use Pjas\Http\Auth;
use Pjas\Http\Csrf;
use Pjas\Http\View;
use Pjas\Support\Config;
use Pjas\Support\Labels;

$u     = Auth::user();
$flash = View::flash();
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$on    = static fn(string $p): string => str_starts_with($path, $p) && ($p !== '/' || $path === '/') ? ' on' : '';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= View::e($title ?? '') ?> · <?= View::e((string) Config::get('site_name', 'pjas-ctrl')) ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="app">
  <aside class="side">
    <a class="brand" href="/"><span class="dot">PJ</span><span>pjas-ctrl<small>サイト管理</small></span></a>
    <button class="btn side-toggle" type="button" aria-expanded="false" aria-controls="side-navigation">メニューを開く</button>
    <div id="side-navigation" class="side-links">
    <a class="nav<?= $on('/operations') ?>" href="/operations">◉ 運用状況</a>
    <div class="sep">サイト管理</div>
    <a class="nav<?= $path === '/' ? ' on' : '' ?>" href="/">▤ ホーム</a>
    <a class="nav<?= $on('/services') ?>" href="/services">▣ サイト一覧</a>
    <?php if (Auth::can('operator')): ?>
      <a class="nav<?= $on('/deploy') ?>" href="/deploy">↥ 新しいバージョンを公開</a>
      <a class="nav<?= $on('/rollback') ?>" href="/rollback">↶ 以前のバージョンに戻す</a>
      <div class="sep">管理</div>
      <?php if (Auth::can('owner')): ?>
        <a class="nav<?= $on('/connections') ?>" href="/connections">⇄ 接続設定と初回準備</a>
        <a class="nav<?= $on('/users') ?>" href="/users">♙ 利用者とサイトの権限</a>
        <a class="nav<?= $on('/daemons') ?>" href="/daemons">⚙ 常駐プログラム</a>
        <a class="nav<?= $on('/scheduled-tasks') ?>" href="/scheduled-tasks">◷ 定期処理</a>
      <?php endif; ?>
      <a class="nav<?= $on('/audit') ?>" href="/audit">☰ 操作の記録</a>
    <?php endif; ?>
    <a class="nav<?= $on('/security') ?>" href="/security">♧ ログインの保護</a>
    <div class="foot">
      <?= View::e($u['email'] ?? '') ?> <span class="tag t-mut"><?= View::e(Labels::role((string) ($u['role'] ?? ''))) ?></span><br>
      公開機能: <?= AgentClient::alive()
        ? '<span class="tag t-ok">利用できます</span>'
        : '<span class="tag t-err">利用できません</span>' ?>
      <form method="post" action="/logout" style="margin-top:8px"><?= Csrf::field() ?>
        <button class="btn sm">ログアウト</button></form>
    </div>
    </div>
  </aside>
  <main class="main">
    <?php if ($flash): ?>
      <div class="note <?= $flash['kind'] === 'err' ? 'err' : 'ok' ?> flash"><?= View::e($flash['text']) ?></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<script src="/assets/navigation.js" defer></script>
</body>
</html>
