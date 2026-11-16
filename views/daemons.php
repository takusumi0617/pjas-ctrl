<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
$states = ['active' => '稼働中', 'inactive' => '停止中', 'failed' => '異常停止', 'activating' => '起動中', 'deactivating' => '停止処理中'];
?>
<h1>常駐プログラム</h1>
<div class="sub">サイトに関連付けたプログラムだけを起動・停止・再起動できます。</div>
<?php if ($error): ?><div class="note err"><?= View::e($error) ?></div><?php endif; ?>
<p><a class="btn" href="/daemons">状態を更新</a></p>
<?php foreach ($services as $site): ?>
<div class="card"><div class="card-h"><a href="/services/<?= View::e($site['name']) ?>"><?= View::e($site['name']) ?></a></div><div class="card-b">
<?php $linked = array_filter($daemons, static fn($d) => $d['service'] === $site['name']); ?>
<?php if (!$linked): ?><p>関連付けたプログラムはありません。</p><?php endif; ?>
<?php foreach ($linked as $d): $state = $d['state']; ?>
<h3><?= View::e($d['label']) ?></h3>
<p class="mono"><?= View::e($d['unit']) ?></p>
<p>状態: <?= View::e(($state['LoadState'] ?? '') === 'not-found' ? 'サーバーに未インストール' : ($states[$state['ActiveState'] ?? ''] ?? '不明')) ?></p>
<?php if (($state['LoadState'] ?? '') === 'loaded'): ?><div class="row">
<?php foreach (['start' => '起動する', 'stop' => '停止する', 'restart' => '再起動する'] as $action => $label): ?>
<form method="post" action="/daemons/action" onsubmit="return confirm('プログラムを操作します。利用中のサイトに影響する場合があります。続けますか？')">
<?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($d['service']) ?>"><input type="hidden" name="unit" value="<?= View::e($d['unit']) ?>"><input type="hidden" name="action" value="<?= $action ?>"><button class="btn"><?= $label ?></button>
</form><?php endforeach; ?></div><?php endif; ?>
<details><summary>設定を編集する</summary>
<form method="post" action="/daemons/save"><?= Csrf::field() ?>
<input type="hidden" name="service" value="<?= View::e($d['service']) ?>"><input type="hidden" name="original_unit" value="<?= View::e($d['unit']) ?>">
<div class="field"><label>関連付けるサイト</label><select name="target_service" required><?php foreach ($services as $option): ?><option value="<?= View::e($option['name']) ?>" <?= $option['name'] === $d['service'] ? 'selected' : '' ?>><?= View::e($option['name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>表示名</label><input name="label" required value="<?= View::e($d['label']) ?>"></div>
<?php if ($d['managed']): ?><div class="field"><label>実行内容</label><textarea name="command" required rows="3"><?= View::e($d['command']) ?></textarea><div class="hint">サイトの公開中フォルダを作業場所として www-data 権限で実行します。変更後は再起動してください。{{site_root}} はこのサイトの公開中フォルダに置き換わります。</div></div>
<?php elseif (!empty($d['execution']['editable'])): ?>
<div class="field"><?php if (!empty($d['follow_paths'])): ?><input type="hidden" name="edit_execution" value="1"><p>起動設定をctrlで管理し、公開先の変更に追従しています。</p><?php else: ?><label class="inline"><input type="checkbox" name="edit_execution" value="1">起動設定もctrlで管理し、公開先の変更に追従する</label><?php endif; ?></div>
<div class="field"><label>起動コマンド</label><textarea name="exec_start" rows="3"><?= View::e($d['execution']['exec_start']) ?></textarea><div class="hint">例: /usr/bin/php8.5 websocket/server.php start。シェルコマンドではなく、実行ファイルの絶対パスから書きます。{{site_root}} は公開中フォルダに置き換わります。環境変数ファイルなどの元設定は維持します。</div></div>
<?php else: ?><p>この既存プログラムは表示名と関連付けのみ編集できます。起動設定を編集できるのは、サイト内で www-data として動く simple/exec 型のプログラムです。</p><?php endif; ?>
<?php if ($d['managed'] || !empty($d['execution']['editable'])): ?>
<div class="field"><label>作業フォルダ（サイト内の相対パス）</label><input name="working_subdir" value="<?= View::e($d['working_subdir'] ?? $d['execution']['working_subdir'] ?? '') ?>" placeholder="空欄なら公開中フォルダの直下"><div class="hint">公開先の切替時は稼働中の追従対象を再起動します。設定を保存した直後は、再起動ボタンで変更を反映してください。</div></div>
<?php endif; ?>
<button class="btn">設定を保存</button></form></details><hr>
<?php endforeach; ?>
<?php if (!$error): ?>
<details><summary>このサイトに常駐プログラムを追加する</summary>
<form method="post" action="/daemons/save"><?= Csrf::field() ?>
<input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><input type="hidden" name="mode" value="new">
<div class="field"><label>表示名</label><input name="label" required placeholder="例: メール送信の作業"></div>
<div class="field"><label>作業フォルダ（サイト内の相対パス）</label><input name="working_subdir" placeholder="空欄なら公開中フォルダの直下"></div>
<div class="field"><label>実行内容</label><textarea name="command" required rows="3" placeholder="exec /usr/bin/php8.5 worker.php"></textarea><div class="hint">動き続けるプログラムを指定します。サイトの公開中フォルダで www-data として実行し、異常終了時は自動で再起動します。保存時には起動しません。</div></div>
<button class="btn">新しく作成する</button></form>
</details>
<details><summary>サーバーにある既存プログラムを関連付ける</summary>
<form method="post" action="/daemons/save"><?= Csrf::field() ?>
<input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><input type="hidden" name="mode" value="existing">
<div class="field"><label>表示名</label><input name="label" required></div>
<div class="field"><label>関連付けるプログラム</label><select name="unit" required><option value="">選んでください</option><?php foreach ($units as $unit): ?><option value="<?= View::e($unit) ?>"><?= View::e($unit) ?></option><?php endforeach; ?></select><div class="hint">そのサイトが使用するプログラムを選んでください。既存の実行設定や稼働状態は変更しません。</div></div>
<div class="field"><label class="inline"><input type="checkbox" name="edit_execution" value="1">起動設定も取り込み、サイトの公開先に追従する</label><div class="hint">このサイト内で www-data として動く simple/exec 型のプログラムが対象です。既存の設定ファイルは残します。保存後に再起動してください。</div></div>
<button class="btn">このサイトに関連付ける</button></form>
</details><?php endif; ?>
</div></div><?php endforeach; ?>
<?php if (!$services): ?><p>先に「サイト一覧」からサイトを登録してください。</p><?php endif; ?>
