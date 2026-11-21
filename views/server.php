<?php
use Pjas\Http\{Csrf,View};use Pjas\Support\Time;
$e=static fn($s)=>View::e((string)$s);$bad=array_filter($checks,static fn($c)=>!$c['ok']);$stale=!\Pjas\Domain\SitePresentation::fresh($last_tick,time());
$critical=array_filter($bad,static fn($c)=>str_contains($c['check_key'],'disk.')||str_contains($c['check_key'],'service.')||str_contains($c['check_key'],'collector'));
?>
<h1>サーバーとctrl <span class="tag <?= $error?'t-warn':($critical?'t-err':($bad||$stale?'t-warn':'t-ok')) ?>"><?= $error?'状態不明':($critical?'すぐ対応':($bad||$stale?'確認が必要':'正常')) ?></span></h1>
<p class="sub">このUbuntu 1台の状態を確認します。サーバー全体の情報と操作は管理者だけが利用できます。</p>
<?php if($error):?><div class="note err"><?= $e($error) ?></div><?php endif;?>
<?php if($stale):?><div class="note err">自動監視が未開始、または停止しています。<a href="/connections">接続設定と初回準備</a>を確認してください。画面を開いたときの測定だけでは自動通知は動きません。</div><?php endif;?>
<p class="hint">自動監視の最終実行: <?= $e(Time::display($last_tick)) ?> ／ 測定: <?= $e(isset($snapshot['time'])?Time::display(gmdate('c',$snapshot['time'])):'未測定') ?>（日本時間）</p>
<details class="card section-gap"><summary class="card-h">管理画面が503になった場合の復旧手順</summary><div class="card-b"><p>接続経路：公開Apache → 管理用HTTP（127.0.0.1:8184）→ 管理用FPMソケット。FPMの停止に伴い管理用HTTPも停止する場合があります。</p><pre><code>sudo systemctl start php<?= View::e(\Pjas\Shared\PhpRuntime::controlVersion()) ?>-fpm.service
sudo systemctl start pjas-control-web.service
sudo systemctl status pjas-control-web.service --no-pager
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8184/</code></pre><p class="hint">正常停止したサービスはsystemctl --failedに出ません。外部からの死活監視も併用してください。</p></div></details>
<div class="grid4"><?php foreach(['cpu'=>'CPU（処理の混雑）','memory'=>'メモリ（作業用の領域）'] as $key=>$label):?><div class="card kpi"><div class="l"><?= $label ?></div><div class="n"><?= isset($snapshot[$key])?$e($snapshot[$key]).'%':'測定待ち' ?></div><div class="hint">90%以上が3回続くと通知</div></div><?php endforeach;?>
<div class="card kpi"><div class="l">起動してから</div><div class="n"><?= isset($snapshot['uptime'])?floor($snapshot['uptime']/86400).'日':'未測定' ?></div></div><div class="card kpi"><div class="l">SSH認証失敗・10分間</div><div class="n"><?= $e($snapshot['ssh_failures']??'未測定') ?></div><div class="hint">20件以上で確認を促します</div></div></div>
<div class="card"><div class="card-h">対応が必要な項目</div><div class="card-b"><?php if(!$bad):?><p><?= $stale?'自動監視を開始して状態を確認してください。':'現在検知されている異常はありません。' ?></p><?php endif;?><?php foreach($bad as $c):?><p><?= \Pjas\Support\Icons::render('error') ?> <?= $e($c['detail']) ?></p><?php endforeach;?><p><a class="btn" href="/operations">各サイト・証明書・バックアップの状態</a> <a class="btn" href="/connections">Discordの通知先を設定・テスト</a></p><p class="hint">異常発生・復旧時に通知します。同じ異常は毎分通知しません。サーバー全体の停止は内部から通知できないため、外部からの死活監視も併用してください。</p></div></div>
<div class="card"><div class="card-h">保存領域</div><table><thead><tr><th>場所</th><th>使用率</th><th>空き容量</th></tr></thead><tbody><?php foreach($snapshot['disks']??[] as $d):?><tr><td><?= $e($d['path']) ?></td><td><?= $d['used']===null?'未測定':$e($d['used']).'%' ?></td><td><?= round($d['free']/1073741824,1) ?> GB</td></tr><?php endforeach;?></tbody></table><div class="card-b hint">空き10%未満、または1GB未満になると通知します。同じ保存装置が複数の場所に表示される場合があります。</div></div>
<div class="card"><div class="card-h">主要サービス</div><table><thead><tr><th>名前</th><th>状態</th><th>操作</th></tr></thead><tbody><?php foreach($snapshot['units']??[] as $u):if(!$u['loaded'])continue;?><tr><td><?= $e($u['unit']) ?></td><td><?= $e(['active'=>'稼働中','activating'=>'起動中','inactive'=>'停止','failed'=>'失敗'][$u['state']]??$u['state']) ?></td><td><?php if($u['unit']==='apache2.service'||preg_match('/\Aphp[0-9]+\.[0-9]+-fpm\.service\z/D',$u['unit'])):?><details><summary>再起動する</summary><form method="post" action="/server"><?= Csrf::field() ?><input type="hidden" name="action" value="restart"><input type="hidden" name="unit" value="<?= $e($u['unit']) ?>"><p>このサービスを使う全サイトに一時的な影響があります。管理用PHPの再起動では、稼働していた管理用HTTPも起動して確認します。設定の検査が通った場合だけ再起動します。</p><label><input type="checkbox" name="confirmed" required> サイトへの影響を確認しました</label><div class="field"><label>現在のパスワード</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn">確認して再起動</button></form></details><?php else:?>状態確認のみ<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php $securityUnits = array_column($snapshot['units'] ?? [], null, 'unit'); ?>
<section class="card section-gap">
<div class="card-h">pjas-security</div>
<div class="card-b"><dl>
<?php foreach (['pjas-security-api.service'=>'API','pjas-security-defense.service'=>'防御サービス'] as $unitName=>$label):
    $unit = $securityUnits[$unitName] ?? null; ?>
<dt><?= $e($label) ?></dt>
<dd><?= $e(!$unit ? '未測定' : (!$unit['loaded'] ? 'unitを読み込めません' : (['active'=>'稼働中','inactive'=>'停止','failed'=>'失敗','activating'=>'起動中'][$unit['state']] ?? $unit['state']))) ?></dd>
<?php endforeach; ?>
</dl></div>
</section>
<details class="card">
<summary class="card-h">主要サービスの停止影響</summary>
<div class="card-b hint">systemd の停止依存と、登録設定から確認した影響候補です。</div>
<table><thead><tr><th>停止するサービス</th><th>影響を受ける可能性がある unit</th><th>登録サイト</th></tr></thead><tbody>
<?php foreach ($snapshot['units'] ?? [] as $unit): if (!$unit['loaded']) continue; ?>
<tr><td><?= $e($unit['unit']) ?></td><td>
<?php if (!isset($unit['stop_dependents'])): ?>未測定
<?php elseif (!$unit['stop_dependents']): ?>直接の依存先なし
<?php else: ?>
<ul><?php foreach ($unit['stop_dependents'] as $dependent): ?><li><?= $e($dependent) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($unit['indirect_stop_dependents'])): ?>
<p>間接の依存先</p><ul><?php foreach ($unit['indirect_stop_dependents'] as $dependent): ?><li><?= $e($dependent) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (isset($unit['stop_impact_complete']) && !$unit['stop_impact_complete']): ?><p class="hint">一部の依存関係は未確認です。</p><?php endif; ?>
</td><td><?= empty($affected_sites[$unit['unit']])?'—':$e(implode('、',$affected_sites[$unit['unit']])) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</details>
<div class="card"><div class="card-h">更新の確認</div><div class="card-b"><p>更新候補 <?= $e($snapshot['updates']['count']??'未確認') ?> 件 ／ セキュリティ更新 <?= $e($snapshot['updates']['security']??'未確認') ?> 件</p><p>一覧取得: <?= $e(!empty($snapshot['updates']['cache_time'])?Time::display(gmdate('c',$snapshot['updates']['cache_time'])):'未確認') ?></p><p class="hint">サーバーに保存された更新一覧を1時間ごとに確認します。最新情報を取得するにはサーバーで <code>sudo apt update</code> を実行してください。自動更新・自動再起動は行いません。全ソフトウェアの脆弱性検査ではありません。</p></div></div>
<div class="card"><div class="card-h">設定ファイルの変更・最新100件</div><div class="card-b hint">初回測定を基準に追加・変更・削除を記録します。内容に秘密情報が含まれるため本文は保存しません。ctrlによる変更も記録されます。<a href="/audit">操作の記録</a>と照合してください。</div><table><thead><tr><th>時刻（日本時間）</th><th>ファイル</th><th>変更</th></tr></thead><tbody><?php foreach(array_reverse($snapshot['changes']??[]) as $c):?><tr><td><?= $e(Time::display(gmdate('c',$c['time']))) ?></td><td><?= $e($c['file']) ?></td><td><?= $e($c['type']) ?></td></tr><?php endforeach;?></tbody></table></div>
<details class="card"><summary class="card-h">24時間の推移（1時間ごとの測定値）</summary><table><thead><tr><th>時刻（日本時間）</th><th>CPU</th><th>メモリ</th><th>ディスク</th></tr></thead><tbody><?php $hour=null;foreach(array_reverse($snapshot['history']??[]) as $h):$bucket=(int)floor($h['time']/3600);if($bucket===$hour)continue;$hour=$bucket;?><tr><td><?= $e(Time::display(gmdate('c',$h['time']))) ?></td><?php foreach(['cpu','memory','disk'] as $key):?><td><?= isset($h[$key])?$e($h[$key]).'%':'測定待ち' ?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></details>
<div class="card"><div class="card-h">サーバーのお知らせ・最新50件</div><table><thead><tr><th>時刻（日本時間）</th><th>内容</th><th>通知</th><th>確認</th></tr></thead><tbody><?php foreach($alerts as $a):?><tr><td><?= $e(Time::display($a['created_at'])) ?></td><td><?= $a['resolved']?'【復旧】':'【確認】' ?><?= $e($a['message']) ?></td><td><?= $a['notified_at']?'送信済み':($a['notification_error']?'送信失敗':'通知なし') ?></td><td><?php if($a['acknowledged']):?>確認済み<?php else:?><form method="post" action="/server"><?= Csrf::field() ?><input type="hidden" name="action" value="ack"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn sm">確認済みにする</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
