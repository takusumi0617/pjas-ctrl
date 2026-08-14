<?php
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\VhostRenderer;
use Pjas\Http\Csrf;
use Pjas\Http\View;

$v = static fn(string $k, string $d = '') => View::e((string) ($in[$k] ?? $d));
?>
<h1>サービス登録</h1>
<div class="sub">/var/www/&lt;サービス名&gt; に releases / shared / current の雛形を作成します</div>

<?php if ($errors): ?>
  <div class="note err flash"><?php foreach ($errors as $e): ?>・<?= View::e($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<form method="post" action="/services">
  <?= Csrf::field() ?>
  <div class="f2">
    <div class="card"><div class="card-h">基本</div><div class="card-b">
      <div class="field"><label>サービス名 (= ディレクトリ名。後から変更できません)</label>
        <input name="name" value="<?= $v('name') ?>" placeholder="billing" required></div>
      <div class="field"><label>ドメイン</label>
        <input name="domain" value="<?= $v('domain') ?>" placeholder="billing.example.com" required></div>
      <div class="field"><label>ランタイム</label>
        <select name="runtime">
          <?php foreach (ServiceRepo::RUNTIMES as $rt): ?>
            <option <?= ($in['runtime'] ?? 'php8.3-fpm') === $rt ? 'selected' : '' ?>><?= View::e($rt) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>DocumentRoot (リリース内の相対パス。空ならリリース直下)</label>
        <input name="docroot" value="<?= $v('docroot', 'public') ?>"></div>
      <div class="field"><label class="inline"><input type="checkbox" name="tls" <?= !empty($in['tls']) ? 'checked' : '' ?>>
        TLS を使う (証明書取得後に有効化してください)</label></div>
    </div></div>

    <div class="card"><div class="card-h">運用</div><div class="card-b">
      <div class="field"><label>ヘルスチェックパス</label>
        <input name="health_path" value="<?= $v('health_path', '/healthz') ?>"></div>
      <div class="field"><label>期待するステータスコード</label>
        <input name="health_status" type="number" value="<?= $v('health_status', '200') ?>"></div>
      <div class="field"><label>保持リリース数</label>
        <input name="keep_releases" type="number" value="<?= $v('keep_releases', '5') ?>"></div>
      <div class="field"><label>shared パス (カンマ / 改行区切り。リリース間で引き継ぐ)</label>
        <textarea name="shared_paths" rows="2"><?= $v('shared_paths', '.env,storage') ?></textarea></div>
      <div class="field"><label>AllowOverride</label>
        <select name="allow_override">
          <?php foreach (['None', 'FileInfo', 'All'] as $ao): ?>
            <option <?= ($in['allow_override'] ?? 'None') === $ao ? 'selected' : '' ?>><?= $ao ?></option>
          <?php endforeach; ?>
        </select></div>
    </div></div>
  </div>

  <div class="card" style="margin-top:14px"><div class="card-h">デプロイ後フック / 追加ディレクティブ</div><div class="card-b">
    <div class="f2">
      <div class="field"><label>after_deploy (1行に1コマンド。許可リスト内のみ)</label>
        <textarea name="hooks" rows="4" class="mono"><?= $v('hooks') ?></textarea>
        <div class="muted" style="font-size:11.5px;margin-top:6px">許可例:
          <?= View::e(implode(' / ', ['composer install --no-dev -o', 'php artisan migrate --force', 'npm ci', 'npm run build'])) ?></div>
      </div>
      <div class="field"><label>vhost 追加ディレクティブ (許可: <?= View::e(implode(', ', VhostRenderer::ALLOWED_DIRECTIVES)) ?>)</label>
        <textarea name="extra_conf" rows="4" class="mono"><?= $v('extra_conf') ?></textarea></div>
    </div>
  </div></div>

  <div class="row" style="margin-top:16px">
    <a class="btn" href="/services">キャンセル</a><span class="spacer"></span>
    <button class="btn p">作成する</button>
  </div>
</form>
