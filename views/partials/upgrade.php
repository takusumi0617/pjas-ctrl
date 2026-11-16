<?php use Pjas\Http\{Auth,View,Csrf};$upgrade=$presentation['observed']['upgrade']??null; ?>
<?php if($upgrade):$phase=$upgrade['phase'];$phases=['planned'=>'計画済み','quiescing'=>'処理の停止中','isolating'=>'隔離を適用中','isolated'=>'隔離の確認済み','prepared'=>'公開前の確認待ち','activating'=>'公開を再開中','active'=>'完了','rolled-back'=>'切り戻し済み']; ?>
<section class="card section-gap"><div class="card-h">直近の移行<span class="spacer"></span><span class="tag <?= in_array($phase,['active','rolled-back'],true)?'t-ok':'t-warn' ?>"><?= View::e($phases[$phase]??$phase) ?></span></div><div class="card-b">
<p class="hint mono">移行ID：<?= View::e($upgrade['id']) ?></p><p><?= View::e($upgrade['source']) ?> → <?= View::e($upgrade['target']) ?></p>
<ol class="workflow-steps"><li class="<?= !in_array($phase,['planned','quiescing'],true)?'complete':'' ?>">退避・処理の停止</li><li class="<?= in_array($phase,['isolated','prepared','activating','active'],true)?'complete':'' ?>">専用ユーザー・PHPの変更</li><li class="<?= $phase==='active'?'complete':'' ?>">公開前のHTTP検査</li><li class="<?= $phase==='active'?'complete':'' ?>">公開と関連処理の再開</li></ol>
<?php if(!empty($upgrade['last_error'])):?><div class="note err" role="alert"><?= View::e($upgrade['last_error']) ?></div><?php endif;?>
<?php if(isset($upgrade['health']['status'])):?><p>直近のHTTP検査：<?= (int)$upgrade['health']['status'] ?> / 期待値 <?= (int)$upgrade['health']['expected'] ?></p><?php endif;?>
<p>関連する常駐処理：<?= count($upgrade['workers']) ?> 件 / cron：<?= count($upgrade['cron_ids']) ?> 件</p>
<?php if($phase==='prepared' && !empty($upgrade['paused']) && Auth::can('owner')):?><details class="runtime-details"><summary>検査して公開を再開する</summary><p>元々稼働していた処理と公開状態を復帰し、HTTP応答を検査します。検査に失敗した場合は停止を維持します。</p>
<form method="post" action="/services/<?= View::e($s['name']) ?>/upgrade/activate"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= View::e($upgrade['id']) ?>"><label class="inline"><input type="checkbox" name="confirm" value="1" required>対象サイトと関連処理、停止中に行った互換性確認の結果を確認しました</label><div class="field"><label for="upgrade-password">現在のパスワード</label><input id="upgrade-password" type="password" name="password" autocomplete="current-password" required></div><button class="btn p">検査して公開を再開</button></form></details><?php endif;?>
<details class="runtime-details"><summary>サーバーでの確認・再開コマンド</summary><pre><code>cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-site-upgrade status <?= View::e($upgrade['id']) ?>
<?php if($phase==='prepared'):?>sudo php8.5 bin/pjas-site-upgrade activate <?= View::e($upgrade['id']) ?> --confirm-activation<?php endif;?></code></pre><p class="hint">準備中に失敗した場合はstatusとroot専用journalを確認します。新しい計画で上書きせず、同じIDで再開または切り戻しを判断してください。</p></details>
</div></section><?php endif;?>
