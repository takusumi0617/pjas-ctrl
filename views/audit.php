<?php
use Pjas\Http\View;
use Pjas\Support\Labels;
?>
<h1>操作の記録</h1>
<div class="sub">「誰が・いつ・何をしたか」がすべて残ります。記録は追加されるだけで、後から書き換えたり消したりはできません
  (パスワードなどの重要な値は記録されません)。</div>

<form method="get" action="/audit" class="row" style="margin-bottom:12px">
  <input name="q" value="<?= View::e($q) ?>" placeholder="ユーザー名 / サイト名で探す" style="max-width:260px">
  <select name="action" style="max-width:260px">
    <option value="">すべての操作</option>
    <?php foreach ([
        'auth.login', 'auth.logout', 'service.create', 'service.update', 'service.deploy',
        'service.rollback', 'service.health', 'apache.vhost.apply', 'tls.issue',
    ] as $actionId): ?>
      <option value="<?= View::e($actionId) ?>" <?= $action === $actionId ? 'selected' : '' ?>>
        <?= View::e(Labels::action($actionId)) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button class="btn">絞り込む</button>
</form>

<div class="card"><table>
  <thead><tr><th>日時</th><th>実行した人</th><th>操作</th><th>対象のサイト</th><th>詳細</th><th>結果</th><th>接続元のアドレス</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mono"><?= View::e($r['at']) ?></td>
      <td><?= View::e($r['actor']) ?></td>
      <td><?= View::e(Labels::action((string) $r['action'])) ?>
          <div class="muted mono" style="font-size:11px"><?= View::e($r['action']) ?></div></td>
      <td><?= View::e($r['target']) ?></td>
      <td class="mono muted"><?= View::e(mb_strimwidth((string) $r['params'], 0, 60, '…')) ?></td>
      <td><?= $r['result'] === 'ok'
            ? '<span class="tag t-ok">成功</span>'
            : '<span class="tag t-err">' . View::e($r['result'] === 'failed' ? '失敗' : $r['result']) . '</span>' ?></td>
      <td class="mono muted"><?= View::e($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">該当する記録がありません</td></tr><?php endif; ?>
  </tbody>
</table></div>
