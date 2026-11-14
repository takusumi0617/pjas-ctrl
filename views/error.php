<?php
require_once dirname(__DIR__).'/app/Support/Icons.php';
use Pjas\Http\View;

$code = (int) ($code ?? (http_response_code() ?: 500));
[$heading, $description] = match ($code) {
    403 => ['この操作は許可されていません', 'このページを開く権限がありません。必要な場合は管理者に確認してください。'],
    404 => ['ページが見つかりません', 'ページが移動したか、削除された可能性があります。URLを確認するか、ホームから探してみてください。'],
    419 => ['画面の有効期限が切れました', '安全のため、操作を中断しました。元の画面を開き直して、もう一度操作してください。'],
    default => ['処理を続けられませんでした', 'サーバーで問題が発生しました。しばらく待ってからやり直してください。解決しない場合は管理者に連絡してください。'],
};
$description = ($message ?? '') !== '' ? $message : $description;
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= View::e($heading) ?> · pjas-ctrl</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="error-page">
<main class="error-panel" aria-labelledby="error-title">
  <a class="error-brand" href="/"><span aria-hidden="true">PJ</span>pjas-ctrl <small>サイト管理</small></a>
  <div class="error-code" aria-label="HTTP <?= $code ?>"><?= $code ?></div>
  <h1 id="error-title"><?= View::e($heading) ?></h1>
  <p class="error-description"><?= View::e($description) ?></p>
  <div class="error-actions">
    <a class="btn p" href="/">ホームに戻る <?= \Pjas\Support\Icons::render('arrow_forward') ?></a>
    <a class="btn" href="/login">ログイン画面へ</a>
  </div>
  <footer class="error-footer"><span>お困りのときは管理者へ</span><span>HTTP <?= $code ?></span></footer>
</main>
</body>
</html>
