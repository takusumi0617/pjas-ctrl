<?php
use Pjas\Http\View;
use Pjas\Support\Labels;

$stateTag = static function (string $s): string {
    $cls = match ($s) { 'healthy' => 't-ok', 'unhealthy' => 't-err', default => 't-mut' };
    return '<span class="tag ' . $cls . '">' . Labels::state($s) . '</span>';
};
$jobTag = static function (string $s): string {
    $cls = match ($s) { 'succeeded' => 't-ok', 'failed' => 't-err', 'running' => 't-warn', default => 't-mut' };
    return '<span class="tag ' . $cls . '">' . Labels::jobState($s) . '</span>';
};
?>
<h1>ホーム</h1>
<div class="sub">この画面で管理しているサイトは <?= (int) $counts['total'] ?> 件です</div>

<?php if (!$agentOk): ?>
  <div class="note err flash">公開作業を行う機能に接続できないため、いまは新しいバージョンの公開や
    公開設定の変更ができません。サーバー管理者に連絡してください。
    <br><span class="muted">確認コマンド: <span class="mono">systemctl status pjas-agentd</span></span></div>
<?php endif; ?>

<div class="grid4">
  <div class="card kpi"><div class="n"><?= (int) $counts['total'] ?></div><div class="l">管理しているサイト</div></div>
  <div class="card kpi"><div class="n" style="color:var(--ok)"><?= (int) $counts['healthy'] ?></div><div class="l">正常に動いている</div></div>
  <div class="card kpi"><div class="n" style="color:var(--err)"><?= (int) $counts['unhealthy'] ?></div><div class="l">確認が必要</div></div>
  <div class="card kpi"><div class="n"><?= (int) $counts['week'] ?></div><div class="l">直近7日の公開回数</div></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-h">サイトの状態<span class="spacer"></span><a class="btn sm" href="/services">すべて表示</a></div>
  <table>
    <thead><tr><th>サイト</th><th>ブラウザで開くアドレス</th><th>サイトの種類</th><th>状態</th><th>最後に確認した日時</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($services, 0, 8) as $s): ?>
      <tr>
        <td><a href="/services/<?= View::e($s['name']) ?>"><b><?= View::e($s['name']) ?></b></a></td>
        <td class="mono"><?= View::e($s['domain']) ?></td>
        <td><?= View::e(Labels::runtime((string) $s['runtime'])) ?></td>
        <td><?= $stateTag((string) $s['state']) ?></td>
        <td class="mono muted"><?= View::e((string) ($s['checked_at'] ?? '—')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?>
      <tr><td colspan="5" class="muted">まだサイトがありません。<a href="/services/new">登録する</a></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="card-h">最近おこなった作業</div>
  <table>
    <thead><tr><th>#</th><th>作業</th><th>サイト</th><th>内容</th><th>実行した人</th><th>開始</th><th>結果</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><a href="/jobs/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td>
        <td><?= View::e(Labels::jobType((string) $j['type'])) ?></td>
        <td><?= View::e((string) ($j['service'] ?? '')) ?></td>
        <td class="muted"><?= View::e(mb_strimwidth((string) $j['detail'], 0, 48, '…')) ?></td>
        <td><?= View::e($j['actor']) ?></td>
        <td class="mono"><?= View::e($j['started_at']) ?></td>
        <td><?= $jobTag((string) $j['state']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="7" class="muted">まだ作業の記録はありません</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
