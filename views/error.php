<?php use Pjas\Http\View; ?>
<h1><?= View::e($title ?? 'エラー') ?></h1>
<div class="sub">処理を続行できませんでした</div>
<div class="card"><div class="card-b"><?= View::e($message ?? '') ?></div></div>
<p style="margin-top:16px"><a href="/">ホームに戻る</a></p>
