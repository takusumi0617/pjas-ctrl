<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
?>
<h1>以前のバージョンに戻す</h1>
<div class="sub">サーバーに残っている過去のプログラムへ切り替えます。</div>
<div class="card"><div class="card-h">戻すサイトを選ぶ</div><div class="card-b">
  <form method="get" action="/rollback" class="row">
    <label for="rollback-service">対象のサイト</label>
    <select id="rollback-service" name="service" required>
      <?php foreach ($services as $site): ?><option value="<?= View::e($site['name']) ?>" <?= $selected === $site['name'] ? 'selected' : '' ?>><?= View::e($site['name'].' ('.$site['domain'].')') ?></option><?php endforeach; ?>
    </select><button class="btn" <?= !$services ? 'disabled' : '' ?>>戻せるバージョンを表示</button>
  </form>
</div></div>
<?php if ($error !== ''): ?><div class="note err" style="margin-top:16px"><?= View::e($error) ?></div>
<?php elseif ($selected !== ''): ?>
  <div class="card" style="margin-top:16px"><div class="card-h">戻すバージョンを選ぶ</div><div class="card-b">
    <p>現在の公開先: <span class="mono"><?= View::e($current ?: '未公開') ?></span></p>
    <div class="note">戻すのはプログラムです。共有データや自動引き継ぎ対象のDBは最新の状態を使います。DBの構造を変更した場合は、以前のプログラムでも動くことを確認してください。動作確認に失敗したら元の公開先へ復帰します。</div>
    <?php if ($versions): ?>
      <form method="post" action="/services/<?= View::e($selected) ?>/rollback">
        <?= Csrf::field() ?><input type="hidden" name="expected_current" value="<?= View::e($current) ?>">
        <div class="field"><label for="rollback-tag">戻すバージョン</label><select id="rollback-tag" name="tag" required>
          <?php foreach ($versions as $version): ?><option value="<?= View::e($version['tag']) ?>"><?= View::e($version['tag'].($version['label'] !== '' ? ' · '.$version['label'] : '')) ?></option><?php endforeach; ?>
        </select></div>
        <button class="btn p">このバージョンに戻す</button>
      </form>
    <?php else: ?><p class="muted">戻せる過去のバージョンがサーバーに残っていません。</p><?php endif; ?>
  </div></div>
<?php endif; ?>
