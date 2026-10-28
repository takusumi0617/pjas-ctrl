<?php
use Pjas\Http\Csrf;
use Pjas\Http\View;
?>
<h1>新しいバージョンを公開</h1>
<div class="sub">新しいバージョンのファイルを公開します。公開先はいったん別の場所に用意してから最後に切り替えるので、
途中で失敗してもいまのサイトは止まりません。</div>

<form method="post" action="/deploy" enctype="multipart/form-data">
  <?= Csrf::field() ?>

  <div class="card step">
    <div class="card-h"><span class="step-n">1</span>どのサイトを更新しますか</div>
    <div class="card-b">
      <div class="field">
        <label>公開先のサイト</label>
        <select name="service" required>
          <?php foreach ($services as $s): ?>
            <option value="<?= View::e($s['name']) ?>" <?= $selected === $s['name'] ? 'selected' : '' ?>>
              <?= View::e($s['name']) ?> (<?= View::e($s['domain']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">更新したいサイトを選びます。ここに無い場合は先に「サイト一覧」から登録してください。</div>
      </div>
    </div>
  </div>

  <div class="card step">
    <div class="card-h"><span class="step-n">2</span>公開するファイルを選ぶ</div>
    <div class="card-b">
      <div class="field">
        <label>ファイルの取得元</label>
        <select name="source" id="source">
          <option value="upload">パソコンから圧縮ファイルをアップロードする (.zip / .tar.gz)</option>
          <option value="git">登録済みのソースコード管理から取得する（Git）</option>
        </select>
        <div class="hint">迷ったら「アップロード」を選んでください。</div>
      </div>

      <div class="field" id="f-upload">
        <label>アップロードするファイル</label>
        <input type="file" name="archive" accept=".zip,.tar.gz,.tgz">
        <div class="hint">サイトのファイル一式を圧縮したものを選びます。
          アップロード後、開く前に安全チェック（サイズ・種類・ファイルの壊れや改ざんがないか）を行います。</div>
      </div>

      <div class="field" id="f-git" hidden>
        <label>取得するバージョン</label>
        <input name="git_ref" placeholder="main">
        <div class="hint">例: <span class="mono">main</span>、<span class="mono">v1.8.2</span>。
          「main」などの開発ライン名、または「v1.8.2」などのバージョン名を入力します。
          取得元には、サイト登録時に指定した公開GitHubリポジトリ、またはサーバー管理者が登録した場所を使います。</div>
      </div>

      <div class="field">
        <label>今回のバージョン名 (任意)</label>
        <input name="label" placeholder="v1.8.2">
        <div class="hint">後から履歴を見たときに分かりやすくするための名前です。空欄でも構いません。</div>
      </div>
    </div>
  </div>

  <div class="card step">
    <div class="card-h"><span class="step-n">3</span>公開のしかたを決める</div>
    <div class="card-b">
      <div class="field">
        <label class="inline"><input type="checkbox" name="auto_rollback" checked>
          公開後の動作確認に失敗したら、自動でひとつ前のバージョンに戻す</label>
        <div class="hint">おすすめの設定です。新しいバージョンでサイトが正しく応答しなかった場合、
          自動で元の状態に戻すので、サイトが壊れたままにはなりません。</div>
      </div>
      <div class="field">
        <label class="inline"><input type="checkbox" name="maintenance">
          作業中は「メンテナンス中」のページを表示する</label>
        <div class="hint">切り替えは一瞬で終わるため通常は不要です。
          データの移行など時間のかかる作業を伴うときだけ使ってください。</div>
      </div>
    </div>
  </div>

  <div class="card step">
    <div class="card-h"><span class="step-n">4</span>内容を確認して実行</div>
    <div class="card-b">
      <p class="muted" style="margin:0 0 12px">「公開を開始」を押すと、次の順番で自動的に進みます。
        進み具合は次の画面でそのまま確認できます。</p>
      <ol class="flow">
        <li><b>ファイルを安全チェックする</b><span>種類・サイズを確認し、不正なファイルが混じっていないか調べます</span></li>
        <li><b>新しいバージョンを別の場所に用意する</b><span>いまのサイトには触れずに、隣に展開して準備します</span></li>
        <li><b>引き継ぐデータをつなぐ</b><span>設定ファイルやアップロード済みのファイルなど、バージョンをまたいで残すものを引き継ぎます</span></li>
        <li><b>公開前の準備処理を実行する</b><span>管理者が登録した処理だけが実行されます</span></li>
        <li><b>設定に間違いがないか検査する</b><span>プログラムとサーバー設定を公開前にチェックします</span></li>
        <li><b>公開先を新しいバージョンに切り替える</b><span>一瞬で切り替わり、サイトは止まりません</span></li>
        <li><b>サイトが正しく動くか確認する</b><span>失敗したときは（設定していれば）自動で元に戻します</span></li>
        <li><b>古いバージョンを片づける</b><span>設定した数だけ残して、それより古いものを削除します</span></li>
      </ol>

      <p class="hint" style="margin:12px 0 0">初めてこのサイトを公開する場合で、すでにサーバーにファイルが置かれているときは、
        その中身を「引き継ぎ」というひとつのバージョンとして自動的に取り込みます。既存のファイルは削除されず、
        うまくいかなかったときはそのバージョンに戻せます。</p>

      <details class="tech">
        <summary>技術的な内容を表示</summary>
        <ol class="muted" style="font-size:13px;padding-left:20px;margin:8px 0 0">
          <li>初回のみ: 既存ファイルを releases/&lt;tag-1s&gt; へ引き継ぎ、current を張って自動ロールバック先にする</li>
          <li>アーカイブ検疫 (サイズ / 拡張子 / パストラバーサル / SHA-256)</li>
          <li>releases/&lt;timestamp&gt; へ展開・所有者と権限の設定</li>
          <li>shared のリンク (.env, storage など)</li>
          <li>after_deploy フック (許可リスト内のみ)</li>
          <li>php -l と apachectl configtest</li>
          <li>current シンボリックリンクを原子的に張り替え、php-fpm reload / apache graceful</li>
          <li>ヘルスチェック (失敗時は直前リリースへ復帰)</li>
          <li>keep_releases を超えた古いリリースを削除</li>
        </ol>
      </details>
    </div>
  </div>

  <div class="row" style="margin-top:16px"><span class="spacer"></span><button class="btn p">公開を開始</button></div>
</form>

<script src="/assets/deploy.js" defer></script>
