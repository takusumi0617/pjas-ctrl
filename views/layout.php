<?php
use Pjas\Agent\AgentClient;
use Pjas\Http\{Auth,Csrf,View};
use Pjas\Support\{Config,Labels,Icons};
$u=Auth::user();$flash=View::flash();
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$nav=static function(string $href,string $icon,string $label)use($path):string {
    $active=$path===$href || ($href!=='/' && str_starts_with($path,$href.'/'));
    return '<a class="nav'.($active?' on':'').'" href="'.View::e($href).'"'.($active?' aria-current="page"':'').'>'.Icons::render($icon).'<span>'.View::e($label).'</span></a>';
};
?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= View::e($title??'') ?> · <?= View::e((string)Config::get('site_name','pjas-ctrl')) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=20261114-icons">
</head><body>
<a class="skip-link" href="#main-content">本文へ移動</a>
<div class="app"><aside class="side">
<a class="brand" href="/"><span class="dot"><?= Icons::render('deployed_code') ?></span><span>pjas-ctrl<small>サイト管理</small></span></a>
<button class="btn side-toggle" type="button" aria-expanded="false" aria-controls="side-navigation"><span data-menu-icon><?= Icons::render('menu') ?></span><span data-close-icon hidden><?= Icons::render('close') ?></span><span data-menu-label>メニューを開く</span></button>
<nav id="side-navigation" class="side-links" aria-label="メインメニュー">
<?= $nav('/operations','monitoring','運用状況') ?>
<div class="sep">サイト管理</div>
<?= $nav('/','home','ホーム') ?>
<?= $nav('/services','dns','サイト一覧') ?>
<?php if(Auth::can('operator')): ?>
<?= $nav('/deploy','cloud_upload','新しいバージョンを公開') ?>
<?= $nav('/rollback','history','以前のバージョンに戻す') ?>
<div class="sep">管理</div>
<?php if(Auth::can('owner')): ?>
<?= $nav('/components','shield','隔離コンポーネント') ?>
<?= $nav('/server','server_person','サーバー') ?>
<?= $nav('/connections','settings_ethernet','接続設定と初回準備') ?>
<?= $nav('/retired-sites','delete_sweep','削除済みサイト') ?>
<?= $nav('/users','manage_accounts','利用者とサイトの権限') ?>
<?= $nav('/daemons','terminal','常駐プログラム') ?>
<?= $nav('/scheduled-tasks','schedule','定期処理') ?>
<?php endif; ?>
<?= $nav('/audit','receipt_long','操作の記録') ?>
<?php endif; ?>
<?= $nav('/security','verified_user','ログインの保護') ?>
<div class="foot">
<div class="account-email"><?= View::e($u['email']??'') ?></div>
<span class="tag t-mut"><?= View::e(Labels::role((string)($u['role']??''))) ?></span>
<div class="agent-status">公開機能: <?= AgentClient::alive()?'<span class="tag t-ok">利用できます</span>':'<span class="tag t-err">利用できません</span>' ?></div>
<form method="post" action="/logout"><?= Csrf::field() ?><button class="btn sm"><?= Icons::render('logout') ?>ログアウト</button></form>
</div></nav></aside>
<main class="main" id="main-content" tabindex="-1">
<?php if($flash): ?><div class="note <?= $flash['kind']==='err'?'err':'ok' ?> flash"><?= View::e($flash['text']) ?></div><?php endif; ?>
<?= $content ?>
</main></div>
<script src="/assets/navigation.js?v=20261114-icons" defer></script>
</body></html>
