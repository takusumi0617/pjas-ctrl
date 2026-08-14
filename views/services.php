<?php
use Pjas\Domain\ReleaseRepo;
use Pjas\Http\Auth;
use Pjas\Http\View;
?>
<div class="row"><h1>サービス</h1><span class="spacer"></span>
  <?php if (Auth::can('owner')): ?><a class="btn p" href="/services/new">+ サービス登録</a><?php endif; ?>
</div>
<div class="sub">/var/www 配下の管理対象</div>

<div class="card">
  <table>
    <thead><tr><th>サービス</th><th>ドメイン</th><th>ランタイム</th><th>現行リリース</th><th>TLS</th><th>状態</th></tr></thead>
    <tbody>
    <?php foreach ($services as $s):
        $cur = ReleaseRepo::current((int) $s['id']); ?>
      <tr>
        <td><a href="/services/<?= View::e($s['name']) ?>"><b><?= View::e($s['name']) ?></b></a>
            <div class="muted mono" style="font-size:11px">/var/www/<?= View::e($s['name']) ?></div></td>
        <td class="mono"><?= View::e($s['domain']) ?></td>
        <td class="mono"><?= View::e($s['runtime']) ?></td>
        <td class="mono"><?= View::e($cur['tag'] ?? '—') ?></td>
        <td><?= $s['tls'] ? '<span class="tag t-ok">有効</span>' : '<span class="tag t-mut">なし</span>' ?></td>
        <td><?= match ((string) $s['state']) {
                'healthy'   => '<span class="tag t-ok">healthy</span>',
                'unhealthy' => '<span class="tag t-err">unhealthy</span>',
                default     => '<span class="tag t-mut">unknown</span>',
            } ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?>
      <tr><td colspan="6" class="muted">サービスが登録されていません</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
