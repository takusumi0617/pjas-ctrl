<?php
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\VhostRenderer;
use Pjas\Http\Auth;
use Pjas\Http\Csrf;
use Pjas\Http\View;

$s    = $service;
$name = View::e($s['name']);
$tabs = ['overview' => '概要', 'releases' => 'リリース', 'vhost' => 'Apache 設定',
         'files' => 'ファイル', 'jobs' => 'ジョブ', 'settings' => '設定'];
?>
<div class="row">
  <h1><?= $name ?></h1>
  <?= match ((string) $s['state']) {
        'healthy'   => '<span class="tag t-ok">healthy</span>',
        'unhealthy' => '<span class="tag t-err">unhealthy</span>',
        default     => '<span class="tag t-mut">unknown</span>',
    } ?>
  <span class="spacer"></span>
  <form method="post" action="/services/<?= $name ?>/health"><?= Csrf::field() ?>
    <button class="btn">ヘルスチェック</button></form>
  <?php if (Auth::can('operator')): ?>
    <a class="btn p" href="/deploy?service=<?= $name ?>">デプロイ</a>
  <?php endif; ?>
</div>
<div class="sub mono"><?= View::e($s['domain']) ?> ·
  <?= View::e($wwwRoot . '/' . $s['name']) ?>/current<?= $current ? ' → releases/' . View::e($current['tag']) : ' (未デプロイ)' ?></div>

<div class="tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="tab<?= $tab === $k ? ' on' : '' ?>" href="/services/<?= $name ?>?tab=<?= $k ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if (!empty($agentErr)): ?>
  <div class="note err flash">agent: <?= View::e($agentErr) ?></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
  <div class="f2">
    <div class="card"><div class="card-h">サービス情報</div><div class="card-b">
      <dl class="kv">
        <dt>ドメイン</dt><dd class="mono"><?= View::e($s['domain']) ?></dd>
        <dt>DocumentRoot</dt><dd class="mono">current/<?= View::e($s['docroot']) ?></dd>
        <dt>ランタイム</dt><dd class="mono"><?= View::e($s['runtime']) ?><?= $s['runtime'] !== 'static'
              ? ' (/run/php/' . View::e($s['runtime']) . '.sock)' : '' ?></dd>
        <dt>ヘルス</dt><dd class="mono">GET <?= View::e($s['health_path']) ?> → <?= (int) $s['health_status'] ?>
            <span class="muted"><?= View::e((string) ($s['checked_at'] ?? '未確認')) ?></span></dd>
        <dt>TLS</dt><dd><?= $s['tls'] ? '有効' : 'なし' ?></dd>
        <dt>保持リリース</dt><dd><?= (int) $s['keep_releases'] ?></dd>
        <dt>shared</dt><dd class="mono"><?= View::e($s['shared_paths']) ?></dd>
        <dt>after_deploy</dt><dd class="mono"><?= nl2br(View::e($s['hooks'] ?: '—')) ?></dd>
      </dl>
    </div></div>
    <div class="card"><div class="card-h">最近のジョブ</div><table>
      <tbody>
      <?php foreach (array_slice($jobs, 0, 8) as $j): ?>
        <tr>
          <td><a href="/jobs/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a> <span class="mono"><?= View::e($j['type']) ?></span></td>
          <td class="mono muted"><?= View::e($j['started_at']) ?></td>
          <td><?= match ((string) $j['state']) {
                'succeeded' => '<span class="tag t-ok">成功</span>',
                'failed'    => '<span class="tag t-err">失敗</span>',
                'running'   => '<span class="tag t-warn">実行中</span>',
                default     => '<span class="tag t-mut">待機</span>' } ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$jobs): ?><tr><td class="muted" style="padding:16px">まだジョブがありません</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($tab === 'releases'): ?>
  <div class="card"><table>
    <thead><tr><th>リリース</th><th>ラベル</th><th>ソース</th><th>サイズ</th><th>実行者</th><th>状態</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($releases as $r): ?>
      <tr>
        <td class="mono"><?= View::e($r['tag']) ?></td>
        <td><?= View::e($r['label']) ?></td>
        <td class="mono muted"><?= View::e(mb_strimwidth((string) $r['source'], 0, 40, '…')) ?></td>
        <td class="mono"><?= $r['size'] ? number_format($r['size'] / 1048576, 1) . ' MB' : '—' ?></td>
        <td><?= View::e($r['deployed_by']) ?></td>
        <td><?= match ((string) $r['state']) {
              'current'  => '<span class="tag t-ok">current</span>',
              'failed'   => '<span class="tag t-err">失敗</span>',
              default    => '<span class="tag t-mut">保持</span>' } ?></td>
        <td>
          <?php if ($r['state'] !== 'current' && $r['state'] !== 'failed' && Auth::can('operator')): ?>
            <form method="post" action="/services/<?= $name ?>/rollback"
                  onsubmit="return confirm('<?= View::e($r['tag']) ?> に切り替えます。よろしいですか?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="tag" value="<?= View::e($r['tag']) ?>">
              <button class="btn sm">このリリースに戻す</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$releases): ?><tr><td colspan="7" class="muted">リリースがありません</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'vhost'): ?>
  <div class="note" style="margin-bottom:12px">
    適用時は <span class="mono">a2ensite → apachectl configtest → graceful</span> の順に実行し、
    configtest が失敗した場合は自動で元の設定に戻します。
    <?php if (!empty($live) && empty($managed)): ?>
      <br><b>この vhost は pjas-ctrl 管理外です</b> (管理ヘッダがありません)。適用すると内容が置き換わります。
    <?php endif; ?>
  </div>
  <div class="f2">
    <div class="card"><div class="card-h">生成される設定
      <span class="spacer"></span><span class="mono muted"><?= View::e('/etc/apache2/sites-available/pjas-' . $s['name'] . '.conf') ?></span></div>
      <div class="card-b"><pre><?= View::e($rendered) ?></pre></div>
    </div>
    <div class="card"><div class="card-h">現行との差分
      <?= trim($live) === trim($rendered) ? '<span class="tag t-ok">差分なし</span>' : '<span class="tag t-warn">未適用の変更あり</span>' ?></div>
      <div class="card-b"><pre class="diff"><?php foreach ($diff as [$sign, $line]):
          $cls = $sign === '+' ? 'add' : ($sign === '-' ? 'del' : 'ctx'); ?><span class="<?= $cls ?>"><?= View::e($sign . ' ' . $line) ?></span>
<?php endforeach; ?></pre></div>
    </div>
  </div>
  <?php if (Auth::can('owner')): ?>
    <div class="row" style="margin-top:16px">
      <form method="post" action="/services/<?= $name ?>/tls"><?= Csrf::field() ?>
        <button class="btn">証明書を取得 / 更新 (certbot)</button></form>
      <span class="spacer"></span>
      <form method="post" action="/services/<?= $name ?>/vhost"
            onsubmit="return confirm('vhost を適用し Apache を graceful リロードします。よろしいですか?')">
        <?= Csrf::field() ?><button class="btn p">適用する</button></form>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'files'): ?>
  <div class="card">
    <div class="card-h"><?= View::e($wwwRoot . '/' . $s['name'] . '/' . ($listing['path'] ?? '')) ?>
      <span class="spacer"></span><span class="muted" style="font-size:12px">releases/ は読み取り専用</span></div>
    <table>
      <thead><tr><th>名前</th><th>種別</th><th>サイズ</th><th>権限</th><th>更新</th></tr></thead>
      <tbody>
      <?php if (!empty($listing['path'])): ?>
        <tr><td><a href="/services/<?= $name ?>?tab=files&path=<?= urlencode(dirname((string) $listing['path']) === '.' ? '' : dirname((string) $listing['path'])) ?>">..</a></td>
            <td colspan="4"></td></tr>
      <?php endif; ?>
      <?php foreach ($listing['entries'] ?? [] as $e): ?>
        <tr>
          <td class="mono">
            <?php if ($e['type'] === 'dir'): ?>
              📁 <a href="/services/<?= $name ?>?tab=files&path=<?= urlencode((string) $e['path']) ?>"><?= View::e($e['name']) ?></a>
            <?php elseif ($e['type'] === 'link'): ?>
              🔗 <?= View::e($e['name']) ?> <span class="muted">→ <?= View::e((string) ($e['target'] ?? '')) ?></span>
            <?php else: ?>
              📄 <?= View::e($e['name']) ?>
            <?php endif; ?>
          </td>
          <td class="muted"><?= View::e($e['type']) ?></td>
          <td class="mono"><?= isset($e['size']) ? number_format((int) $e['size']) : '' ?></td>
          <td class="mono muted"><?= View::e((string) ($e['mode'] ?? '')) ?> <?= View::e((string) ($e['owner'] ?? '')) ?></td>
          <td class="mono muted"><?= View::e((string) ($e['mtime'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($listing['entries'])): ?><tr><td colspan="5" class="muted">項目がありません</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'jobs'): ?>
  <div class="card"><table>
    <thead><tr><th>#</th><th>種別</th><th>詳細</th><th>実行者</th><th>開始</th><th>終了</th><th>結果</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><a href="/jobs/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td>
        <td class="mono"><?= View::e($j['type']) ?></td>
        <td class="mono muted"><?= View::e(mb_strimwidth((string) $j['detail'], 0, 60, '…')) ?></td>
        <td><?= View::e($j['actor']) ?></td>
        <td class="mono"><?= View::e($j['started_at']) ?></td>
        <td class="mono"><?= View::e((string) ($j['finished_at'] ?? '')) ?></td>
        <td><?= match ((string) $j['state']) {
              'succeeded' => '<span class="tag t-ok">成功</span>',
              'failed'    => '<span class="tag t-err">失敗</span>',
              'running'   => '<span class="tag t-warn">実行中</span>',
              default     => '<span class="tag t-mut">待機</span>' } ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="7" class="muted">ジョブがありません</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'settings'): ?>
  <?php if (!Auth::can('owner')): ?>
    <div class="note">設定の変更には owner 権限が必要です。</div>
  <?php endif; ?>
  <form method="post" action="/services/<?= $name ?>/settings">
    <?= Csrf::field() ?>
    <div class="f2">
      <div class="card"><div class="card-h">基本</div><div class="card-b">
        <div class="field"><label>ドメイン</label><input name="domain" value="<?= View::e($s['domain']) ?>"></div>
        <div class="field"><label>ランタイム</label><select name="runtime">
          <?php foreach (ServiceRepo::RUNTIMES as $rt): ?>
            <option <?= $s['runtime'] === $rt ? 'selected' : '' ?>><?= View::e($rt) ?></option>
          <?php endforeach; ?></select></div>
        <div class="field"><label>DocumentRoot</label><input name="docroot" value="<?= View::e($s['docroot']) ?>"></div>
        <div class="field"><label>AllowOverride</label><select name="allow_override">
          <?php foreach (['None', 'FileInfo', 'All'] as $ao): ?>
            <option <?= $s['allow_override'] === $ao ? 'selected' : '' ?>><?= $ao ?></option>
          <?php endforeach; ?></select></div>
        <div class="field"><label class="inline"><input type="checkbox" name="tls" <?= $s['tls'] ? 'checked' : '' ?>> TLS を使う</label></div>
      </div></div>
      <div class="card"><div class="card-h">運用</div><div class="card-b">
        <div class="field"><label>ヘルスチェックパス</label><input name="health_path" value="<?= View::e($s['health_path']) ?>"></div>
        <div class="field"><label>期待ステータス</label><input name="health_status" type="number" value="<?= (int) $s['health_status'] ?>"></div>
        <div class="field"><label>保持リリース数</label><input name="keep_releases" type="number" value="<?= (int) $s['keep_releases'] ?>"></div>
        <div class="field"><label>shared パス</label><textarea name="shared_paths" rows="2"><?= View::e($s['shared_paths']) ?></textarea></div>
      </div></div>
    </div>
    <div class="card" style="margin-top:14px"><div class="card-h">フック / 追加ディレクティブ</div><div class="card-b"><div class="f2">
      <div class="field"><label>after_deploy</label><textarea name="hooks" rows="4" class="mono"><?= View::e($s['hooks']) ?></textarea></div>
      <div class="field"><label>vhost 追加ディレクティブ (許可: <?= View::e(implode(', ', VhostRenderer::ALLOWED_DIRECTIVES)) ?>)</label>
        <textarea name="extra_conf" rows="4" class="mono"><?= View::e($s['extra_conf']) ?></textarea></div>
    </div></div></div>
    <?php if (Auth::can('owner')): ?>
      <div class="row" style="margin-top:16px"><span class="spacer"></span><button class="btn p">保存する</button></div>
    <?php endif; ?>
  </form>
<?php endif; ?>
