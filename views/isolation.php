<?php use Pjas\Shared\PhpRuntime; use Pjas\Http\View; use Pjas\Http\Csrf; use Pjas\Http\Auth; require_once dirname(__DIR__).'/shared/PhpRuntime.php'; ?>
<div class="card"><div class="card-h">隔離とPHPアップグレード</div><div class="card-b">
<?php if(isset($isolationError)): ?><p class="hint"><?= View::e($isolationError) ?></p>
<?php elseif(isset($isolation)): ?>
<p>現在: <?= !empty($isolation['isolated'])?'サイト専用ユーザーで隔離':'共通ユーザー（未隔離）' ?> / <?= View::e($isolation['runtime']) ?></p>
<p>サイトを一時停止してから適用します。ファイル権限とApache設定を退避し、専用ユーザー・サイト別FPMへ切り替えます。完了後も停止を維持します。対象アプリのPHP互換性と応答を確認してから再開してください。</p>
<?php foreach($isolation['warnings']??[] as $warning): ?><p class="hint"><?= View::e($warning) ?></p><?php endforeach; ?>
<?php foreach($isolation['blockers'] as $blocker): ?><p class="hint"><?= View::e($blocker) ?></p><?php endforeach; ?>
<?php if(Auth::can('owner')): ?><form method="post" action="/services/<?= $name ?>/isolation">
<?= Csrf::field() ?>
<div class="field"><label>移行先PHP</label><select name="runtime">
<?php $available=array_column($runtimeCatalog??[],'available','runtime');foreach(PhpRuntime::RUNTIMES as $runtime): ?><option <?= isset($available[$runtime])&&!$available[$runtime]?'disabled':'' ?> value="<?= View::e($runtime) ?>" <?= $runtime===PhpRuntime::DEFAULT_RUNTIME?'selected':'' ?>><?= View::e($runtime) ?><?= isset($available[$runtime])&&!$available[$runtime]?'（CLI/FPM未導入）':'' ?><?= $runtime==='php8.6-fpm'?'（先行準備用・導入と互換性確認が必要）':'' ?></option><?php endforeach; ?>
</select></div>
<div class="field"><label>現在のパスワード</label><input type="password" name="password" autocomplete="current-password" required></div>
<label class="inline"><input type="checkbox" name="confirm" value="1" required>バックアップと作業時間を確保し、外部で起動したワーカー・定期処理も停止しました</label>
<label class="inline"><input type="checkbox" name="standardize" value="1" required>Apacheを標準設定に切り替え、独自の継承設定・追加設定・別名ドメインを退避することを確認しました</label>
<button class="btn p" <?= !empty($isolation['blockers'])?'disabled':'' ?>>隔離・PHP移行を適用</button>
</form><?php endif; ?>
<?php endif; ?>
</div></div>
