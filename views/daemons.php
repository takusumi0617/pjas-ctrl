<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
$states = ['active' => '稼働中', 'inactive' => '停止中', 'failed' => '異常停止', 'activating' => '起動中', 'deactivating' => '停止処理中'];
?>
<h1>常駐プログラム</h1>
<div class="sub">サーバーで動き続けるプログラムの状態確認と、起動・停止・再起動ができます。</div>
<?php if ($error): ?><div class="note err"><?= View::e($error) ?></div><?php endif; ?>
<p><a class="btn" href="/daemons">状態を更新</a></p>
<?php if (!$error && !$daemons): ?><p>管理対象が登録されていません。サーバーの agent/config.php の managed_daemons に登録してください。</p><?php endif; ?>
<?php foreach ($daemons as $d): $state = $d['state']; ?>
<div class="card"><div class="card-h"><?= View::e($d['label']) ?></div><div class="card-b">
<p class="mono"><?= View::e($d['unit']) ?></p>
<p>状態: <?= View::e(($state['LoadState'] ?? '') === 'not-found' ? 'サーバーに未インストール' : ($states[$state['ActiveState'] ?? ''] ?? '不明')) ?></p>
<p>サーバー起動時: <?= View::e(match ($state['UnitFileState'] ?? '') { 'enabled', 'enabled-runtime' => '自動起動する', 'disabled' => '自動起動しない', default => '設定による' }) ?></p>
<?php if (($state['LoadState'] ?? '') === 'loaded'): ?>
<div class="row">
<?php foreach (['start' => '起動する', 'stop' => '停止する', 'restart' => '再起動する'] as $action => $label): ?>
<form method="post" action="/daemons/action" onsubmit="return confirm('このプログラムを操作します。停止・再起動の間は利用中のサイトに影響する場合があります。続けますか？')">
<?= Csrf::field() ?><input type="hidden" name="unit" value="<?= View::e($d['unit']) ?>"><input type="hidden" name="action" value="<?= $action ?>"><button class="btn"><?= $label ?></button>
</form>
<?php endforeach; ?>
</div><?php endif; ?>
</div></div>
<?php endforeach; ?>
