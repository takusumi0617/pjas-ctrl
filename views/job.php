<?php use Pjas\Http\View; ?>
<div class="row">
  <h1>ジョブ #<?= (int) $job['id'] ?></h1>
  <span class="mono muted"><?= View::e($job['type']) ?></span>
  <span id="state"><?= match ((string) $job['state']) {
        'succeeded' => '<span class="tag t-ok">成功</span>',
        'failed'    => '<span class="tag t-err">失敗</span>',
        'running'   => '<span class="tag t-warn">実行中</span>',
        default     => '<span class="tag t-mut">待機</span>' } ?></span>
  <span class="spacer"></span>
  <?php if ($service): ?><a class="btn" href="/services/<?= View::e($service) ?>"><?= View::e($service) ?> に戻る</a><?php endif; ?>
</div>
<div class="sub">
  <?= View::e($job['actor']) ?> · 開始 <?= View::e($job['started_at']) ?>
  <?= $job['finished_at'] ? ' · 終了 ' . View::e($job['finished_at']) : '' ?>
  <?= $job['detail'] ? ' · ' . View::e($job['detail']) : '' ?>
</div>

<div class="card"><div class="card-h">実行ログ<span class="spacer"></span>
  <span class="mono muted" id="stream-state">接続中…</span></div>
  <div class="card-b"><div class="log" id="log"><?= View::e($log) ?></div></div>
</div>

<div id="job-meta" data-id="<?= (int) $job['id'] ?>" data-state="<?= View::e($job['state']) ?>" hidden></div>
<script src="/assets/job.js" defer></script>
