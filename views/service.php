<?php
use Pjas\Domain\ServiceRepo;
use Pjas\Domain\VhostRenderer;
use Pjas\Http\Auth;
use Pjas\Http\Csrf;
use Pjas\Http\View;
use Pjas\Support\Labels;

$s    = $service;
$name = View::e($s['name']);
$tabs = ['overview' => '概要', 'releases' => 'バージョン履歴', 'vhost' => '公開設定',
         'files' => 'ファイル', 'jobs' => '作業の履歴', 'settings' => '設定'];
?>
<div class="row">
  <h1><?= $name ?></h1>
  <?= match ((string) $s['state']) {
        'healthy'   => '<span class="tag t-ok">正常</span>',
        'unhealthy' => '<span class="tag t-err">異常</span>',
        default     => '<span class="tag t-mut">未確認</span>',
    } ?>
  <span class="spacer"></span>
  <form method="post" action="/services/<?= $name ?>/health"><?= Csrf::field() ?>
    <button class="btn">いま動作を確認する</button></form>
  <?php if (Auth::can('operator')): ?>
    <a class="btn p" href="/deploy?service=<?= $name ?>">新しいバージョンを公開</a>
  <?php endif; ?>
</div>
<div class="sub"><span class="mono"><?= View::e($s['domain']) ?></span> ·
  <?= $current
        ? '公開中のバージョン: <span class="mono">' . View::e($current['label'] ?: $current['tag']) . '</span>'
        : 'まだ一度も公開されていません' ?>
  <span class="muted mono" style="font-size:11px">(<?= View::e($wwwRoot . '/' . $s['name']) ?>/current)</span></div>

<div class="tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="tab<?= $tab === $k ? ' on' : '' ?>" href="/services/<?= $name ?>?tab=<?= $k ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if (!empty($agentErr)): ?>
  <div class="note err flash">サーバー側の作業プログラムでエラーが起きました: <?= View::e($agentErr) ?></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
  <?php if ($current === null && Auth::can('operator')): ?>
    <div class="note" style="margin-bottom:14px">
      このサイトはまだ管理下のバージョンを持っていません。
      すでにサーバーにファイルが置かれている場合は、それをそのまま最初のバージョンとして取り込めます
      （新しくファイルを用意する必要はありません）。
      <form method="post" action="/services/<?= $name ?>/adopt" style="margin-top:8px"
            onsubmit="return confirm('いま置かれているファイルを最初のバージョンとして取り込みます。よろしいですか?')">
        <?= Csrf::field() ?><button class="btn">既存ファイルを取り込む</button>
      </form>
    </div>
  <?php endif; ?>
  <div class="f2">
    <div class="card"><div class="card-h">このサイトの設定</div><div class="card-b">
      <dl class="kv">
        <dt>公開アドレス</dt><dd class="mono"><?= View::e($s['domain']) ?></dd>
        <dt>公開するフォルダ</dt><dd class="mono">current/<?= View::e($s['docroot']) ?></dd>
        <dt>サイトの種類</dt><dd><?= View::e(Labels::runtime((string) $s['runtime'])) ?></dd>
        <dt>動作確認</dt><dd><span class="mono"><?= View::e($s['health_path']) ?></span> を開いて
            <span class="mono"><?= (int) $s['health_status'] ?></span> が返れば正常
            <div class="muted">最終チェック: <?= View::e((string) ($s['checked_at'] ?? 'まだ確認していません')) ?></div></dd>
        <dt>通信の暗号化</dt><dd><?= $s['tls'] ? 'https を使用' : '使用しない' ?></dd>
        <dt>残す過去バージョン</dt><dd><?= (int) $s['keep_releases'] ?> 件</dd>
        <dt>引き継ぐファイル</dt><dd class="mono"><?= View::e($s['shared_paths']) ?></dd>
        <dt>公開後に自動実行</dt><dd class="mono"><?= nl2br(View::e($s['hooks'] ?: '—')) ?></dd>
      </dl>
    </div></div>
    <div class="card"><div class="card-h">最近おこなった作業</div><table>
      <tbody>
      <?php foreach (array_slice($jobs, 0, 8) as $j): ?>
        <tr>
          <td><a href="/jobs/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a> <?= View::e(Labels::jobType((string) $j['type'])) ?></td>
          <td class="mono muted"><?= View::e($j['started_at']) ?></td>
          <td><?= match ((string) $j['state']) {
                'succeeded' => '<span class="tag t-ok">成功</span>',
                'failed'    => '<span class="tag t-err">失敗</span>',
                'running'   => '<span class="tag t-warn">実行中</span>',
                default     => '<span class="tag t-mut">待機中</span>' } ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$jobs): ?><tr><td class="muted" style="padding:16px">まだ作業の記録はありません</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($tab === 'releases'): ?>
  <div class="sub">これまでに公開したバージョンの一覧です。問題が起きたときは、ここから以前のバージョンに戻せます。</div>
  <div class="card"><table>
    <thead><tr><th>バージョン名</th><th>公開日時 (識別子)</th><th>取得元</th><th>サイズ</th><th>公開した人</th><th>状態</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($releases as $r): ?>
      <tr>
        <td><b><?= View::e($r['label'] ?: '(名前なし)') ?></b></td>
        <td class="mono muted"><?= View::e($r['tag']) ?></td>
        <td class="mono muted"><?= View::e(mb_strimwidth((string) $r['source'], 0, 40, '…')) ?></td>
        <td class="mono"><?= $r['size'] ? number_format($r['size'] / 1048576, 1) . ' MB' : '—' ?></td>
        <td><?= View::e($r['deployed_by']) ?></td>
        <td><?= match ((string) $r['state']) {
              'current'  => '<span class="tag t-ok">公開中</span>',
              'failed'   => '<span class="tag t-err">失敗</span>',
              default    => '<span class="tag t-mut">保管中</span>' } ?></td>
        <td>
          <?php if ($r['state'] !== 'current' && $r['state'] !== 'failed' && Auth::can('operator')): ?>
            <form method="post" action="/services/<?= $name ?>/rollback"
                  onsubmit="return confirm('公開中のバージョンを「<?= View::e($r['label'] ?: $r['tag']) ?>」に戻します。よろしいですか?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="tag" value="<?= View::e($r['tag']) ?>">
              <button class="btn sm">このバージョンに戻す</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$releases): ?><tr><td colspan="7" class="muted">まだ公開したバージョンがありません</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'vhost'): ?>
  <div class="sub">このサイトをどのアドレスで公開するかという、Web サーバー (Apache) 側の設定です。
    設定タブの内容から自動で作られるので、ふだんは「反映する」を押すだけで大丈夫です。</div>
  <div class="note" style="margin-bottom:12px">
    反映するときは、まず設定内容に誤りがないか検査してから切り替えます。
    検査に失敗した場合は自動で元の設定に戻すため、サイトが止まることはありません。
    <?php if (!empty($live) && empty($managed)): ?>
      <br><b>現在の設定はこの管理画面の管理下にありません。</b>反映すると、いまの設定内容は置き換わります。
    <?php endif; ?>
    <?php if (!empty($conflicts)): ?>
      <br>同じ公開アドレスを使っている古い設定が有効になっています。反映時に自動で無効化します:
      <span class="mono"><?= View::e(implode(', ', $conflicts)) ?></span>
    <?php endif; ?>
  </div>
  <div class="f2">
    <div class="card"><div class="card-h">これから使うサーバー設定（詳しい人向け）
      <span class="spacer"></span><span class="mono muted"><?= View::e('/etc/apache2/sites-available/pjas-' . $s['name'] . '.conf') ?></span></div>
      <div class="card-b"><pre><?= View::e($rendered) ?></pre></div>
    </div>
    <div class="card"><div class="card-h">現在との変更点（詳しい人向け）
      <?= trim($live) === trim($rendered)
            ? '<span class="tag t-ok">違いはありません</span>'
            : '<span class="tag t-warn">まだ反映していない変更があります</span>' ?></div>
      <div class="card-b"><pre class="diff"><?php foreach ($diff as [$sign, $line]):
          $cls = $sign === '+' ? 'add' : ($sign === '-' ? 'del' : 'ctx'); ?><span class="<?= $cls ?>"><?= View::e($sign . ' ' . $line) ?></span>
<?php endforeach; ?></pre></div>
    </div>
  </div>
  <?php if (Auth::can('owner')): ?>
    <div class="row" style="margin-top:16px">
      <span class="spacer"></span>
      <form method="post" action="/services/<?= $name ?>/vhost"
            onsubmit="return confirm('この設定を反映します。サイトを止めずに切り替わります。よろしいですか?')">
        <?= Csrf::field() ?><button class="btn p">反映する</button></form>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'files'): ?>
  <div class="card">
    <div class="card-h"><?= View::e($wwwRoot . '/' . $s['name'] . '/' . ($listing['path'] ?? '')) ?>
      <span class="spacer"></span><span class="muted" style="font-size:12px">公開済みバージョンのファイルは閲覧のみ (変更できません)</span></div>
    <table>
      <thead><tr><th>名前</th><th>種類</th><th>サイズ (バイト)</th><th>変更できる人</th><th>更新日時</th></tr></thead>
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
          <td class="muted"><?= View::e(Labels::entryType((string) $e['type'])) ?></td>
          <td class="mono"><?= isset($e['size']) ? number_format((int) $e['size']) : '' ?></td>
          <td class="mono muted"><?= View::e((string) ($e['mode'] ?? '')) ?> <?= View::e((string) ($e['owner'] ?? '')) ?></td>
          <td class="mono muted"><?= View::e((string) ($e['mtime'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($listing['entries'])): ?><tr><td colspan="5" class="muted">このフォルダは空です</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'jobs'): ?>
  <div class="card"><table>
    <thead><tr><th>#</th><th>作業</th><th>内容</th><th>実行した人</th><th>開始</th><th>終了</th><th>結果</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><a href="/jobs/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td>
        <td><?= View::e(Labels::jobType((string) $j['type'])) ?></td>
        <td class="muted"><?= View::e(mb_strimwidth((string) $j['detail'], 0, 60, '…')) ?></td>
        <td><?= View::e($j['actor']) ?></td>
        <td class="mono"><?= View::e($j['started_at']) ?></td>
        <td class="mono"><?= View::e((string) ($j['finished_at'] ?? '')) ?></td>
        <td><?= match ((string) $j['state']) {
              'succeeded' => '<span class="tag t-ok">成功</span>',
              'failed'    => '<span class="tag t-err">失敗</span>',
              'running'   => '<span class="tag t-warn">実行中</span>',
              default     => '<span class="tag t-mut">待機中</span>' } ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="7" class="muted">まだ作業の記録はありません</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'settings'): ?>
  <div class="sub">このサイトの設定です。公開アドレスや公開フォルダを変えたときは、保存したあと
    「公開設定」タブで反映してください。</div>
  <?php if (!Auth::can('owner')): ?>
    <div class="note">設定を変更できるのは「すべて管理できる」権限の利用者だけです。現在は内容の確認のみできます。</div>
  <?php endif; ?>
  <form method="post" action="/services/<?= $name ?>/settings">
    <?= Csrf::field() ?>
    <div class="card step"><div class="card-h">基本</div><div class="card-b">
      <div class="field"><label>ブラウザで開くアドレス</label>
        <input name="domain" value="<?= View::e($s['domain']) ?>">
        <div class="hint">利用者がブラウザで開くアドレスです。変更後は「公開設定」タブで反映が必要です。</div></div>

      <div class="field"><label>サイトの種類</label><select name="runtime">
        <?php foreach (ServiceRepo::RUNTIMES as $rt): ?>
          <option value="<?= View::e($rt) ?>" <?= $s['runtime'] === $rt ? 'selected' : '' ?>>
            <?= View::e(Labels::runtime($rt)) ?></option>
        <?php endforeach; ?></select>
        <div class="hint">PHP で動くサイトかどうかを選びます。HTML や画像だけのサイトは「静的ファイルのみ」です。</div></div>

      <div class="field"><label>公開するフォルダ</label>
        <input name="docroot" value="<?= View::e($s['docroot']) ?>">
        <div class="hint">アップロードしたファイルのうち、実際にブラウザへ公開する場所です
          (多くの PHP アプリでは <span class="mono">public</span>)。</div></div>

      <div class="field"><label>フォルダごとの設定ファイル (.htaccess) の扱い</label><select name="allow_override">
        <?php
        $aoLabels = [
            'None'     => '使わない (推奨・いちばん安全)',
            'FileInfo' => 'URL の書き換えだけ許可する',
            'All'      => 'すべて許可する',
        ];
        foreach ($aoLabels as $ao => $aoLabel): ?>
          <option value="<?= $ao ?>" <?= $s['allow_override'] === $ao ? 'selected' : '' ?>>
            <?= $aoLabel ?> (<?= $ao ?>)</option>
        <?php endforeach; ?></select>
        <div class="hint">WordPress など、URL の書き換えルールが必要なアプリでは「URL の書き換えだけ許可する」を選びます。</div></div>

      <div class="field"><label class="inline"><input type="checkbox" name="tls" <?= $s['tls'] ? 'checked' : '' ?>>
        通信を暗号化して公開する（https）</label>
        <div class="hint">Cloudflare Origin CA の共通ワイルドカード証明書を使用します。</div></div>
    </div></div>

    <div class="card step"><div class="card-h">動作確認とバージョン管理</div><div class="card-b">
      <div class="field"><label>動作確認に使うページ</label>
        <input name="health_path" value="<?= View::e($s['health_path']) ?>">
        <div class="hint">公開のたびに、このページを開いてサイトが正常に動いているか自動で確認します。</div></div>

      <div class="field"><label>正常なときに返る番号</label>
        <input name="health_status" type="number" value="<?= (int) $s['health_status'] ?>">
        <div class="hint">通常は <span class="mono">200</span>（正常）のままで大丈夫です。</div></div>

      <div class="field"><label>残しておく過去バージョンの数</label>
        <input name="keep_releases" type="number" value="<?= (int) $s['keep_releases'] ?>">
        <div class="hint">多いほど古い状態に戻せますが、その分ディスクを使います。</div></div>

      <div class="field"><label>バージョンをまたいで引き継ぐファイル・フォルダ</label>
        <textarea name="shared_paths" rows="2"><?= View::e($s['shared_paths']) ?></textarea>
        <div class="hint">設定ファイルや利用者がアップロードした画像など、公開のたびに消えては困るものを指定します
          (カンマまたは改行区切り)。</div></div>
    </div></div>

    <div class="card step"><div class="card-h">詳細設定（分からなければそのままで大丈夫です）</div><div class="card-b">
      <div class="field"><label>公開のあとに自動実行する処理</label>
        <textarea name="hooks" rows="4" class="mono"><?= View::e($s['hooks']) ?></textarea>
        <div class="hint">1 行に 1 つずつ書きます。安全のため、あらかじめ許可されたものだけが実行できます。</div></div>

      <div class="field"><label>Apache 設定に追加する行（上級者向け）</label>
        <textarea name="extra_conf" rows="4" class="mono"><?= View::e($s['extra_conf']) ?></textarea>
        <div class="hint">通常は空欄で構いません。WebSocket 転送や別フォルダのAPIにも対応します。<br>
          <span class="mono">{{site_root}}</span> はサイトの公開中フォルダ、
          <span class="mono">{{document_root}}</span> は公開するフォルダに置き換わります。<br>
          <span class="mono">&lt;Directory {{document_root}}&gt; ... &lt;/Directory&gt;</span> 内に画面の配信設定を書けます。使える項目:
          <span class="mono"><?= View::e(implode(', ', VhostRenderer::ALLOWED_DIRECTIVES)) ?></span></div></div>
        <?php if ($s['name'] === 'pjas-chat'): ?>
        <details><summary>pjas-chat 用の設定例（コピーして上の欄へ貼り付け）</summary>
        <p>公開するフォルダを dist、PHP を php8.4-fpm に設定してください。追加欄に既存の指定がある場合は内容を確認して統合します。</p>
        <textarea readonly rows="15" class="mono">&lt;Directory {{document_root}}&gt;
    Require all granted
    FallbackResource /index.html
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' wss:; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"
&lt;/Directory&gt;
ProxyPass /ws ws://127.0.0.1:8081
ProxyPassReverse /ws ws://127.0.0.1:8081
ProxyPassMatch ^/api(?:/.*)?$ unix:/run/php/php8.4-fpm.sock|fcgi://localhost{{site_root}}/api/index.php</textarea>
        <p>Apache の proxy・proxy_fcgi・proxy_wstunnel・rewrite・headers・ssl モジュールが必要です。WebSocket の常駐プログラムは別途起動してください。</p>
        </details>
        <?php endif; ?>
    </div></div>

    <?php if (Auth::can('owner')): ?>
      <div class="row" style="margin-top:16px"><span class="spacer"></span><button class="btn p">保存する</button></div>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php if (Auth::can('owner')): ?><p><a class="btn" href="/daemons">このサイトの常駐プログラムを管理</a></p><?php endif; ?>
