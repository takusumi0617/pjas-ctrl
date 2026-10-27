<?php
use Pjas\Domain\ServiceRepo;
use Pjas\Http\Csrf;
use Pjas\Http\View;
use Pjas\Support\Labels;
?>
<h1>サイトの一括登録</h1>
<div class="sub">サーバーに置かれていて、まだ管理下に無いフォルダを一覧にしました。
  登録するものを選んでください。ファイルの中身から推測した値を入れてありますが、そのまま登録せずに確認してください。</div>

<?php if (!empty($summary)): ?>
  <div class="note <?= empty(array_filter($results, static fn($r) => !$r['ok'])) ? 'ok' : '' ?> flash"><?= View::e($summary) ?></div>
<?php endif; ?>

<?php if (!empty($results)): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-h">登録結果</div>
    <table>
      <thead><tr><th>サイト</th><th>結果</th><th>内容</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?php if ($r['ok']): ?><a href="/services/<?= View::e($r['name']) ?>"><b><?= View::e($r['name']) ?></b></a>
              <?php else: ?><b><?= View::e($r['name']) ?></b><?php endif; ?></td>
          <td><?= $r['ok'] ? '<span class="tag t-ok">成功</span>' : '<span class="tag t-err">失敗</span>' ?></td>
          <td class="muted"><?= View::e($r['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="row"><a class="btn" href="/services/import">続けて登録する</a>
    <a class="btn p" href="/services">サイト一覧へ</a></div>
  <?php return; ?>
<?php endif; ?>

<?php if (!empty($agentErr)): ?>
  <div class="note err flash">サーバー側の作業プログラムに接続できないため、一覧を取得できませんでした: <?= View::e($agentErr) ?></div>
<?php elseif (!$found): ?>
  <div class="note ok flash"><?= View::e($root) ?> に、まだ登録されていないフォルダはありません。</div>
<?php else: ?>

<form method="post" action="/services/import">
  <?= Csrf::field() ?>
  <div class="card">
    <div class="card-h"><?= View::e($root) ?> で見つかったフォルダ<span class="spacer"></span>
      <span class="muted" style="font-size:12px"><?= count($found) ?> 件</span></div>
    <table>
      <thead><tr>
        <th style="width:34px"></th><th>フォルダ名</th><th>中身</th>
        <th>公開アドレス</th><th>実行環境</th><th>公開するフォルダ</th>
      </tr></thead>
      <tbody>
      <?php foreach ($found as $f): $n = View::e($f['name']); ?>
        <tr>
          <td><?php if ($f['eligible']): ?>
              <input type="checkbox" name="pick[]" value="<?= $n ?>" <?= $f['files'] > 0 ? 'checked' : '' ?>>
            <?php endif; ?></td>
          <td><b><?= $n ?></b>
            <?php if (!$f['eligible']): ?>
              <div class="hint">この名前は登録できません（半角小文字の英数字とハイフン、32 文字以内）。
                フォルダ名を変えてから読み込み直してください。</div>
            <?php elseif (!empty($f['managed'])): ?>
              <div class="hint">管理用フォルダ (releases / shared) が既にあります。以前の登録の残りかもしれません。</div>
            <?php endif; ?></td>
          <td class="muted"><?= (int) $f['files'] ?> 件
            <?php if ($f['files'] === 0): ?><br><span class="hint">空のフォルダです</span><?php endif; ?></td>
          <td><input name="row[<?= $n ?>][domain]" value="<?= View::e($f['domain']) ?>"
                     placeholder="<?= $n ?>.example.com" <?= $f['eligible'] ? '' : 'disabled' ?>>
            <?php if ($f['domain'] === ''): ?>
              <div class="hint">既存の Apache 設定から見つかりませんでした。入力してください。</div>
            <?php endif; ?></td>
          <td><select name="row[<?= $n ?>][runtime]" <?= $f['eligible'] ? '' : 'disabled' ?>>
              <?php foreach (ServiceRepo::RUNTIMES as $rt): ?>
                <option value="<?= View::e($rt) ?>" <?= $f['runtime'] === $rt ? 'selected' : '' ?>>
                  <?= View::e(Labels::runtime($rt)) ?></option>
              <?php endforeach; ?></select></td>
          <td><input name="row[<?= $n ?>][docroot]" value="<?= View::e($f['docroot']) ?>"
                     placeholder="(フォルダ直下)" <?= $f['eligible'] ? '' : 'disabled' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card step" style="margin-top:14px">
    <div class="card-h">登録するときの動作</div>
    <div class="card-b">
      <div class="field"><label class="inline"><input type="checkbox" name="adopt" checked>
        いま置かれているファイルを、そのまま最初のバージョンとして取り込む</label>
        <div class="hint">おすすめの設定です。既存のファイルがバージョン履歴に載り、あとから戻せるようになります。
          ファイルの中身は変わりません。</div></div>
      <p class="hint" style="margin:0">登録しただけでは配信は切り替わりません。
        各サイトの「公開設定」タブで反映すると、この管理画面が作る設定に切り替わります
        （同じアドレスを使っている古い設定は、そのとき自動で無効化されます）。</p>
    </div>
  </div>

  <div class="row" style="margin-top:16px">
    <a class="btn" href="/services">キャンセル</a><span class="spacer"></span>
    <button class="btn p">選んだサイトを登録する</button>
  </div>
</form>
<?php endif; ?>
