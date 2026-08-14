<?php
use Pjas\Http\View;

$stateTag = static function (string $s): string {
    return match ($s) {
        'healthy'   => '<span class="tag t-ok">healthy</span>',
        'unhealthy' => '<span class="tag t-err">unhealthy</span>',
        default     => '<span class="tag t-mut">unknown</span>',
    };
};
$jobTag = static function (string $s): string {
    return match ($s) {
        'succeeded' => '<span class="tag t-ok">成功</span>',
        'failed'    => '<span class="tag t-err">失敗</span>',
        'running'   => '<span class="tag t-warn">実行中</span>',
        default     => '<span class="tag t-mut">待機</span>',
    };
};
?>
<h1>ダッシュボード</h1>
<div class="sub"><?= (int) $counts['total'] ?> サービス / /var/www 配下</div>

<?php if (!$agentOk): ?>
  <div class="note err flash">pjas-agentd に接続できません。デプロイと Apache 操作は実行できません。
    <span class="mono">systemctl status pjas-agentd</span> を確認してください。</div>
<?php endif; ?>

<div class="grid4">
  <div class="card kpi"><div class="n"><?= (int) $counts['total'] ?></div><div class="l">登録サービス</div></div>
  <div class="card kpi"><div class="n" style="color:var(--ok)"><?= (int) $counts['healthy'] ?></div><div class="l">healthy</div></div>
  <div class="card kpi"><div class="n" style="color:var(--err)"><?= (int) $counts['unhealthy'] ?></div><div class="l">要対応</div></div>
  <div class="card kpi"><div class="n"><?= (int) $counts['week'] ?></div><div class="l">直近7日のデプロイ</div></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-h">サービス<span class="spacer"></span><a class="btn sm" href="/services">すべて表示</a></div>
  <table>
    <thead><tr><th>サービス</th><th>ドメイン</th><th>ランタイム</th><th>状態</th><th>最終確認</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($services, 0, 8) as $s): ?>
      <tr>
        <td><a href="/services/<?= View::e($s['name']) ?>"><b><?= View::e($s['name']) ?></b></a></td>
        <td class="mono"><?= View::e($s['domain']) ?></td>
        <td class="mono"><?= View::e($s['runtime']) ?></td>
        <td><?= $stateTag((string) $s['state']) ?></td>
        <td class="mono muted"><?= View::e((string) ($s['checked_at'] ?? '—')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?>
      <tr><td colspan="5" class="muted">まだサービスがありません。<a href="/services/new">登録する</a></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="card-h">最近のジョブ</div>
  <table>
    <thead><tr><th>#</th><th>種別</th><th>サービス</th><th>詳細</th><th>実行者</th><th>開始</th><th>結果</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><a href="/jobs/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td>
        <td class="mono"><?= View::e($j['type']) ?></td>
        <td><?= View::e((string) ($j['service'] ?? '')) ?></td>
        <td class="mono muted"><?= View::e(mb_strimwidth((string) $j['detail'], 0, 48, '…')) ?></td>
        <td><?= View::e($j['actor']) ?></td>
        <td class="mono"><?= View::e($j['started_at']) ?></td>
        <td><?= $jobTag((string) $j['state']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="7" class="muted">ジョブ履歴はまだありません</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
