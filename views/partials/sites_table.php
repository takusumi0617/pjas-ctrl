<?php use Pjas\Http\View;use Pjas\Support\{Time,Labels}; ?>
<div class="table-scroll"><table class="sites-table"><thead><tr><th>サイト</th><th>公開・稼働</th><th>実行環境</th><th>最終確認（日本時間）</th></tr></thead><tbody>
<?php foreach($services as $s):$p=$s['presentation']??\Pjas\Domain\SitePresentation::summarize($s,[],null);$o=$p['observed']??[];?>
<tr><td><a href="/services/<?= View::e($s['name']) ?>"><b><?= View::e($s['name']) ?></b></a><small class="muted mono"><?= View::e($s['domain']) ?></small></td>
<td><span class="tag <?= $p['tone'] ?>"><?= View::e($p['label']) ?></span><small class="muted"><?= View::e($p['reason']) ?></small></td>
<td><?= $p['component']?'処理ごとの専用環境':View::e(Labels::runtime((string)($o['isolation']['runtime']??$s['runtime']))) ?><small class="muted"><?= $p['component']?'実行環境タブで各PHP・実行ユーザーを確認':(!isset($o['isolation'])?'隔離状態：未確認':(!empty($o['isolation']['isolated'])?'サイト専用ユーザー':'共通ユーザー・移行が必要')) ?></small></td>
<td><?= View::e(Time::display($p['checked_at']??null)) ?><small class="muted"><?= \Pjas\Domain\SitePresentation::fresh($p['checked_at']??null,time())?'3分以内の確認':'最新の確認が必要' ?></small></td></tr>
<?php endforeach;?><?php if(!$services):?><tr><td colspan="4">まだサイトがありません。管理者はサイトを登録してください。</td></tr><?php endif;?></tbody></table></div>
