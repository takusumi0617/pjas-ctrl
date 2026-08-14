<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ログイン · pjas-ctrl</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="login">
  <div class="brand" style="justify-content:center"><span class="dot">PJ</span><span>pjas-ctrl</span></div>
  <?php if (!empty($error)): ?><div class="note err flash"><?= View::e($error) ?></div><?php endif; ?>
  <div class="card"><div class="card-b">
    <form method="post" action="/login">
      <?= Csrf::field() ?>
      <div class="field"><label>メールアドレス</label><input name="email" type="email" autofocus required></div>
      <div class="field"><label>パスワード</label><input name="password" type="password" required></div>
      <button class="btn p" style="width:100%">ログイン</button>
    </form>
  </div></div>
</div>
</body>
</html>
