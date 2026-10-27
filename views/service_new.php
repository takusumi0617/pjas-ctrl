<?php
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\VhostRenderer;
use Pjas\Http\Csrf;
use Pjas\Http\View;
use Pjas\Support\Labels;

$v = static fn(string $k, string $d = '') => View::e((string) ($in[$k] ?? $d));
?>
<h1>サイトを登録する</h1>
<div class="sub">このサーバーで新しくサイトを管理できるようにします。ファイルの置き場所とバージョン管理用のフォルダが自動で作られます
  （ファイルの公開はこの後の「新しいバージョンを公開」で行います）。</div>

<?php if ($errors): ?>
  <div class="note err flash"><?php foreach ($errors as $e): ?>・<?= View::e($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<form method="post" action="/services">
  <?= Csrf::field() ?>

  <div class="card step">
    <div class="card-h"><span class="step-n">1</span>サイトの基本情報</div>
    <div class="card-b">
      <div class="field"><label>サイト名</label>
        <input name="name" value="<?= $v('name') ?>" placeholder="billing" required>
        <div class="hint">管理画面と保存先フォルダに使う名前です（半角英数字）。
          <b>あとから変更できません</b>ので、分かりやすい名前を付けてください。</div></div>

      <div class="field"><label>ブラウザで開くアドレス</label>
        <input name="domain" value="<?= $v('domain') ?>" placeholder="billing.example.com" required>
        <div class="hint">例: <span class="mono">billing.example.com</span>。このアドレスからサーバーへつながるように、事前にドメイン名（DNS）の設定が必要です。</div></div>

      <div class="field"><label>サイトの種類</label>
        <select name="runtime">
          <?php foreach (ServiceRepo::RUNTIMES as $rt): ?>
            <option value="<?= View::e($rt) ?>" <?= ($in['runtime'] ?? 'php8.4-fpm') === $rt ? 'selected' : '' ?>>
              <?= View::e(Labels::runtime($rt)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">PHP で動くサイトかどうかを選びます。HTML や画像だけのサイトは「静的ファイルのみ」を選んでください。</div></div>

      <div class="field"><label>公開するフォルダ</label>
        <input name="docroot" value="<?= $v('docroot', 'public') ?>">
        <div class="hint">アップロードするファイルの中で、実際にブラウザへ公開する場所です。
          多くの PHP アプリでは <span class="mono">public</span> のままで大丈夫です。全体を公開する場合は空欄にします。</div></div>

      <div class="field"><label class="inline"><input type="checkbox" name="tls" <?= !empty($in['tls']) ? 'checked' : '' ?>>
        通信を暗号化して公開する（https）</label>
        <div class="hint">安全な通信に必要な証明書がまだ無い場合は、いったんオフのまま登録し、
          サイト画面の「公開設定」から証明書を取得してからオンにしてください。</div></div>
    </div>
  </div>

  <div class="card step">
    <div class="card-h"><span class="step-n">2</span>動作確認とバージョン管理</div>
    <div class="card-b">
      <div class="field"><label>動作確認に使うページ</label>
        <input name="health_path" value="<?= $v('health_path', '/healthz') ?>">
        <div class="hint">公開のたびに、このページを開いてサイトが正常に動いているかを自動で確認します。
          専用のページが無ければ <span class="mono">/</span> でも構いません。</div></div>

      <div class="field"><label>正常なときに返る番号</label>
        <input name="health_status" type="number" value="<?= $v('health_status', '200') ?>">
        <div class="hint">上のページを開いたときに返ってくるはずの番号です。通常は <span class="mono">200</span>（正常）のままで大丈夫です。</div></div>

      <div class="field"><label>残しておく過去バージョンの数</label>
        <input name="keep_releases" type="number" value="<?= $v('keep_releases', '5') ?>">
        <div class="hint">この数だけ過去のバージョンをサーバーに残します。多いほど古い状態に戻せますが、その分ディスクを使います。</div></div>

      <div class="field"><label>バージョンをまたいで引き継ぐファイル・フォルダ</label>
        <textarea name="shared_paths" rows="2"><?= $v('shared_paths', '.env,storage') ?></textarea>
        <div class="hint">設定ファイルや利用者がアップロードした画像など、公開のたびに消えては困るものを指定します
          （カンマまたは改行区切り）。通常は初期値のままで大丈夫です。</div></div>
    </div>
  </div>

  <div class="card step">
    <div class="card-h"><span class="step-n">3</span>詳細設定（分からなければそのままで大丈夫です）</div>
    <div class="card-b">
      <div class="field"><label>フォルダごとの設定ファイル (.htaccess) の扱い</label>
        <select name="allow_override">
          <?php
          $aoLabels = [
              'None'     => '使わない (推奨・いちばん安全)',
              'FileInfo' => 'URL の書き換えだけ許可する',
              'All'      => 'すべて許可する',
          ];
          foreach ($aoLabels as $ao => $aoLabel): ?>
            <option value="<?= $ao ?>" <?= ($in['allow_override'] ?? 'None') === $ao ? 'selected' : '' ?>>
              <?= $aoLabel ?> (<?= $ao ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="hint">サイトのフォルダに置かれた <span class="mono">.htaccess</span> をどこまで有効にするかの設定です。
          WordPress など、書き換えルールが必要なアプリでは「URL の書き換えだけ許可する」を選びます。</div></div>

      <div class="field"><label>公開のあとに自動実行する処理</label>
        <textarea name="hooks" rows="4" class="mono"><?= $v('hooks') ?></textarea>
        <div class="hint">1 行に 1 つずつ書きます。安全のため、あらかじめ許可されたものだけが実行できます。
          使える例: <?= View::e(implode(' / ', ['composer install --no-dev -o', 'php artisan migrate --force', 'npm ci', 'npm run build'])) ?></div></div>

      <div class="field"><label>Apache 設定に追加する行（上級者向け）</label>
        <textarea name="extra_conf" rows="4" class="mono"><?= $v('extra_conf') ?></textarea>
        <div class="hint">通常は空欄のままで構いません。使える項目:
          <span class="mono"><?= View::e(implode(', ', VhostRenderer::ALLOWED_DIRECTIVES)) ?></span></div></div>
    </div>
  </div>

  <div class="row" style="margin-top:16px">
    <a class="btn" href="/services">キャンセル</a><span class="spacer"></span>
    <button class="btn p">この内容で登録する</button>
  </div>
</form>
