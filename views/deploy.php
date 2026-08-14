<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
?>
<h1>新規デプロイ</h1>
<div class="sub">アーカイブのアップロード、または Git ref から。切替は releases/ 展開後に current を張り替える方式です。</div>

<form method="post" action="/deploy" enctype="multipart/form-data">
  <?= Csrf::field() ?>
  <div class="f2">
    <div class="card"><div class="card-h">対象とソース</div><div class="card-b">
      <div class="field"><label>サービス</label>
        <select name="service" required>
          <?php foreach ($services as $s): ?>
            <option value="<?= View::e($s['name']) ?>" <?= $selected === $s['name'] ? 'selected' : '' ?>>
              <?= View::e($s['name']) ?> (<?= View::e($s['domain']) ?>)
            </option>
          <?php endforeach; ?>
        </select></div>

      <div class="field"><label>ソース</label>
        <select name="source" id="source">
          <option value="upload">アーカイブをアップロード (.zip / .tar.gz)</option>
          <option value="git">Git ref</option>
        </select></div>

      <div class="field" id="f-upload">
        <label>アーカイブ</label>
        <input type="file" name="archive" accept=".zip,.tar.gz,.tgz">
        <div class="muted" style="font-size:11.5px;margin-top:6px">SHA-256 を記録し、展開前に検疫します</div>
      </div>

      <div class="field" id="f-git" hidden>
        <label>Git ref (ブランチ / タグ / コミット)</label>
        <input name="git_ref" placeholder="main">
        <div class="muted" style="font-size:11.5px;margin-top:6px">リポジトリ URL は agent 側の許可リストで固定されています</div>
      </div>

      <div class="field"><label>リリースラベル (任意)</label><input name="label" placeholder="v1.8.2"></div>
    </div></div>

    <div class="card"><div class="card-h">オプション</div><div class="card-b">
      <div class="field"><label class="inline"><input type="checkbox" name="auto_rollback" checked>
        ヘルスチェック失敗時に自動ロールバック</label></div>
      <div class="field"><label class="inline"><input type="checkbox" name="maintenance">
        メンテナンスページを表示して実施</label></div>
      <h2>実行される手順</h2>
      <ol class="muted" style="font-size:13px;padding-left:20px;margin:0">
        <li>アーカイブ検疫 (サイズ / 拡張子 / パストラバーサル / SHA-256)</li>
        <li>releases/&lt;timestamp&gt; へ展開・所有者と権限の設定</li>
        <li>shared のリンク</li>
        <li>after_deploy フック (許可リスト内のみ)</li>
        <li>php -l と apachectl configtest</li>
        <li>current を張り替え、php-fpm reload / apache graceful</li>
        <li>ヘルスチェック (失敗時は直前リリースへ復帰)</li>
        <li>保持数を超えた古いリリースを削除</li>
      </ol>
    </div></div>
  </div>

  <div class="row" style="margin-top:16px"><span class="spacer"></span><button class="btn p">デプロイ実行</button></div>
</form>

<script src="/assets/deploy.js" defer></script>
