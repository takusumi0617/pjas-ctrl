<?php use Pjas\Http\View; ?>
<h1>監査ログ</h1>
<div class="sub">追記のみ。特権操作はすべてここに記録されます (機微な値は記録しません)</div>

<form method="get" action="/audit" class="row" style="margin-bottom:12px">
  <input name="q" value="<?= View::e($q) ?>" placeholder="ユーザー / 対象" style="max-width:260px">
  <input name="action" value="<?= View::e($action) ?>" placeholder="操作 (例: service.deploy)" style="max-width:220px">
  <button class="btn">絞り込む</button>
</form>

<div class="card"><table>
  <thead><tr><th>時刻</th><th>ユーザー</th><th>操作</th><th>対象</th><th>パラメータ</th><th>結果</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mono"><?= View::e($r['at']) ?></td>
      <td><?= View::e($r['actor']) ?></td>
      <td class="mono"><?= View::e($r['action']) ?></td>
      <td><?= View::e($r['target']) ?></td>
      <td class="mono muted"><?= View::e(mb_strimwidth((string) $r['params'], 0, 60, '…')) ?></td>
      <td><?= $r['result'] === 'ok'
            ? '<span class="tag t-ok">ok</span>'
            : '<span class="tag t-err">' . View::e($r['result']) . '</span>' ?></td>
      <td class="mono muted"><?= View::e($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">該当する記録がありません</td></tr><?php endif; ?>
  </tbody>
</table></div>
