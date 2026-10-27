<?php
use Pjas\Domain\ReleaseRepo;
use Pjas\Http\Auth;
use Pjas\Http\View;
use Pjas\Support\Labels;
?>
<div class="row"><h1>サイト一覧</h1><span class="spacer"></span>
  <?php if (Auth::can('owner')): ?>
    <a class="btn" href="/services/import">サーバーから一括登録</a>
    <a class="btn p" href="/services/new">+ サイトを登録</a>
  <?php endif; ?>
</div>
<div class="sub">このサーバーで管理しているサイトの一覧です。名前をクリックすると詳細を開けます。</div>

<div class="card">
  <table>
    <thead><tr><th>サイト</th><th>ブラウザで開くアドレス</th><th>サイトの種類</th><th>公開中のバージョン</th><th>通信の暗号化</th><th>状態</th></tr></thead>
    <tbody>
    <?php foreach ($services as $s):
        $cur = ReleaseRepo::current((int) $s['id']); ?>
      <tr>
        <td><a href="/services/<?= View::e($s['name']) ?>"><b><?= View::e($s['name']) ?></b></a>
            <div class="muted mono" style="font-size:11px">/var/www/<?= View::e($s['name']) ?></div></td>
        <td class="mono"><?= View::e($s['domain']) ?></td>
        <td><?= View::e(Labels::runtime((string) $s['runtime'])) ?></td>
        <td class="mono"><?= View::e($cur ? ($cur['label'] ?: $cur['tag']) : '—') ?></td>
        <td><?= $s['tls'] ? '<span class="tag t-ok">https を使用</span>' : '<span class="tag t-mut">使用しない</span>' ?></td>
        <td><?= match ((string) $s['state']) {
                'healthy'   => '<span class="tag t-ok">正常</span>',
                'unhealthy' => '<span class="tag t-err">異常</span>',
                default     => '<span class="tag t-mut">未確認</span>',
            } ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?>
      <tr><td colspan="6" class="muted">まだサイトが登録されていません</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
