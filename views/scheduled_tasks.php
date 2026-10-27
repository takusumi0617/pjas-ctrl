<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
?>
<h1>定期処理</h1>
<div class="sub">サイトごとに、決めた時刻でコマンドを自動実行します。処理はrootではなく
  <span class="mono">www-data</span> として、公開中のバージョンのフォルダで動きます。</div>

<div class="note" style="margin-bottom:16px">
  コマンドは自由に入力できます。同じ実行ユーザーのため、他サイトから読めるファイルにもアクセスできます。
  信頼できる処理だけを登録し、パスワードやAPIキーは入力しないでください。
</div>

<form method="post" action="/scheduled-tasks">
  <?= Csrf::field() ?>
  <div class="card step"><div class="card-h">新しい定期処理</div><div class="card-b">
    <div class="f2">
      <div class="field"><label>対象サイト</label><select name="service" required>
        <?php foreach ($services as $s): ?><option value="<?= View::e($s['name']) ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label>表示名</label><input name="name" placeholder="5分ごとの集計" required></div>
    </div>
    <div class="field"><label>実行時刻</label><input name="schedule" class="mono" value="*/5 * * * *" required>
      <div class="hint">左から「分・時・日・月・曜日」です。例: 毎時0分は <span class="mono">0 * * * *</span>、毎日午前3時は <span class="mono">0 3 * * *</span>。</div></div>
    <div class="field"><label>実行するコマンド</label><textarea name="command" rows="4" class="mono" placeholder="php artisan schedule:run" required></textarea>
      <div class="hint">パイプやリダイレクトを含むシェルコマンドも使用できます。実行場所は <span class="mono">/var/www/サイト名/current</span> です。</div></div>
    <label class="inline"><input type="checkbox" name="enabled" checked>有効にする</label>
  </div></div>
  <div class="row"><span class="spacer"></span><button class="btn p">登録する</button></div>
</form>

<h2>登録済みの定期処理</h2>
<?php foreach ($tasks as $t): ?>
  <div class="card" style="margin-bottom:14px"><div class="card-h">
    <?= View::e($t['service']) ?> · <?= View::e($t['name']) ?>
    <span class="spacer"></span><?= $t['enabled'] ? '<span class="tag t-ok">有効</span>' : '<span class="tag t-mut">停止中</span>' ?>
  </div><div class="card-b">
    <form method="post" action="/scheduled-tasks/<?= (int) $t['id'] ?>">
      <?= Csrf::field() ?>
      <div class="f2">
        <div class="field"><label>表示名</label><input name="name" value="<?= View::e($t['name']) ?>" required></div>
        <div class="field"><label>実行時刻</label><input name="schedule" class="mono" value="<?= View::e($t['schedule']) ?>" required></div>
      </div>
      <div class="field"><label>実行するコマンド</label><textarea name="command" rows="3" class="mono" required><?= View::e($t['command']) ?></textarea></div>
      <label class="inline"><input type="checkbox" name="enabled" <?= $t['enabled'] ? 'checked' : '' ?>>有効にする</label>
      <div class="row" style="margin-top:12px"><span class="muted mono">ログ: /var/log/pjas/cron/task-<?= (int) $t['id'] ?>.log</span><span class="spacer"></span><button class="btn p">更新する</button></div>
    </form>
    <form method="post" action="/scheduled-tasks/<?= (int) $t['id'] ?>/delete" style="margin-top:8px" onsubmit="return confirm('この定期処理を削除しますか?')">
      <?= Csrf::field() ?><button class="btn d">削除する</button>
    </form>
  </div></div>
<?php endforeach; ?>
<?php if (!$tasks): ?><div class="card"><div class="card-b muted">まだ定期処理はありません</div></div><?php endif; ?>
