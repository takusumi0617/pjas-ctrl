<?php
use Pjas\Http\View;
use Pjas\Support\Labels;
?>
<div class="row">
  <h1>作業 #<?= (int) $job['id'] ?></h1>
  <span class="muted"><?= View::e(Labels::jobType((string) $job['type'])) ?></span>
  <span id="state" aria-live="polite"><?= match ((string) $job['state']) {
        'succeeded' => '<span class="tag t-ok">成功</span>',
        'failed'    => '<span class="tag t-err">失敗</span>',
        'running'   => '<span class="tag t-warn">実行中</span>',
        default     => '<span class="tag t-mut">待機中</span>' } ?></span>
  <span class="spacer"></span>
  <?php if ($service): ?><a class="btn" href="/services/<?= View::e($service) ?>"><?= View::e($service) ?> の画面に戻る</a><?php endif; ?>
</div>
<?php if(str_starts_with($job['type'],'site-')&&\Pjas\Http\Auth::can('owner')):?><p><a class="btn p" href="/retired-sites">削除済みサイトで結果・復元を確認する</a></p><?php endif;?>
<div class="sub">
  <?= View::e($job['actor']) ?> · 開始 <?= View::e(\Pjas\Support\Time::display($job['started_at'])) ?>
  <?= $job['finished_at'] ? ' · 終了 ' . View::e(\Pjas\Support\Time::display($job['finished_at'])) : '' ?>
  <?= $job['detail'] ? ' · ' . View::e($job['detail']) : '' ?>
</div>

<?php if(!empty($prepared) && \Pjas\Http\Auth::canSite($service,'operator')): ?><div class="note ok">公開準備ができました。<a class="btn p" href="/deploy/review?service=<?= urlencode($service) ?>&amp;token=<?= View::e($prepared['token']) ?>">変更点を確認して次へ進む</a></div><?php endif; ?>
<?php if($service && in_array($job['type'],['backup','data-restore','preview'],true) && \Pjas\Http\Auth::canSite($service,'operator')): ?><p><a class="btn p" href="/services/<?= View::e($service) ?>/operations?section=<?= $job['type']==='preview'?'preview':'backups' ?>"><?= $job['type']==='preview'?'試験URL・ログイン情報を確認する':'バックアップ・復元の結果を確認する' ?></a></p><?php endif; ?>
<?php if(!in_array($job['state'],['succeeded','failed'],true) && !is_readable(\Pjas\Domain\JobRepo::statusPath((int)$job['id'])) && !\Pjas\Domain\SitePresentation::fresh($job['started_at'],time())):?><div class="note job-diagnostic" role="alert">実行記録を確認できず、待機状態が続いています。実行中でないと断定せず、管理者がagentの記録とプロセスを照合してください。<?php if($service && \Pjas\Http\Auth::can('owner')):?><pre><code>sudo php8.5 bin/pjas-site-upgrade jobs <?= View::e($service) ?></code></pre><?php endif;?></div><?php endif;?>
<div class="card"><div class="card-h">進み具合<span class="spacer"></span>
  <span class="mono muted" id="stream-state" role="status">接続しています…</span><button id="stream-reconnect" class="btn sm" type="button" hidden>ログへ再接続</button></div>
  <div class="card-b">
    <p class="hint" style="margin:0 0 10px">作業の内容が上から順に表示されます。エラーが出た場合はこの内容をサーバー管理者にお伝えください。</p>
    <div class="log" id="log"><?= View::e($log) ?></div>
  </div>
</div>

<div id="job-meta" data-id="<?= (int) $job['id'] ?>" data-state="<?= View::e($job['state']) ?>" data-type="<?= View::e($job['type']) ?>" data-offset="<?= strlen($log) ?>" hidden></div>
<script src="/assets/job.js?v=20261117-resume" defer></script>
