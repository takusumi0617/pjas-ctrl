<?php
use Pjas\Http\{Csrf, View};
use Pjas\Support\Time;
$e = View::e(...);
$url = '/server/security-events';
$inventory = $sections['inventory'] ?? [];
$detection = $sections['detection'] ?? [];
?>
<p><a href="<?= $url ?>">検知・防御の管理に戻る</a></p>
<h1>pjas-security の監視</h1>
<?php foreach ($errors as $section=>$error): ?><p role="alert"><?= $e($section) ?> ／ <?= $e($error) ?></p><?php endforeach; ?>
<section class="card section-gap">
<div class="card-h">検知アラート</div>
<div class="table-scroll"><table><thead><tr><th>ルール</th><th>対象・内容</th><th>重要度</th><th>対応</th></tr></thead><tbody>
<?php foreach ($sections['alerts'] ?? [] as $alert): ?>
<tr><td><?= $e($alert['rule']) ?></td><td><?= $e($alert['title']) ?><small><?= $e($alert['subject']) ?></small></td>
<td><?= $e($alert['severity']) ?></td><td><?= $e($alert['status']) ?>
<?php if (!empty($alert['acknowledged_by'])): ?><small><?= $e($alert['acknowledged_by']) ?></small><?php endif; ?>
<?php if ($alert['status']==='open'): ?><form method="post" action="<?= $url ?>"><?= Csrf::field() ?>
<input type="hidden" name="alert" value="<?= $e($alert['id']) ?>">
<button class="btn" name="action" value="acknowledge">確認済みにする</button></form><?php endif; ?>
</td></tr>
<?php endforeach; ?>
<?php if (empty($sections['alerts'])): ?><tr><td colspan="4">表示できるアラートがありません</td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="card section-gap"><div class="card-h">通知と履歴整備</div><div class="card-b">
<dl>
<dt>検知の状態</dt><dd><?= $e($detection['status']??'未取得') ?></dd>
<?php if (isset($detection['discord_enabled'])): ?>
<dt>Discord 通知条件</dt><dd><?= $detection['discord_enabled']?'有効':'無効' ?> ／ 最低重要度 <?= $e($detection['thresholds']['notify_min_severity']??'未取得') ?></dd>
<?php endif; ?>
<dt>Discord 配信</dt><dd><?= $e($detection['delivery']['status']??'未取得') ?> ／ 待機 <?= (int)($detection['pending_notifications']??0) ?> ／ 失敗 <?= (int)($detection['failed_notifications']??0) ?></dd>
<dt>履歴・バックアップ</dt><dd><?= $e($sections['operations']['status']??'未取得') ?> ／ 保存 <?= (int)($sections['operations']['backups_count']??0) ?> 件</dd>
</dl>
</div></section>

<section class="card section-gap"><div class="card-h">プロセス・待受ポート・接続</div><div class="card-b">
<p>収集状態 <?= $e($inventory['status']??'未取得') ?> ／ <?= $e(Time::display($inventory['captured_at']??null)) ?></p>
<?php foreach ([
'processes'=>['プロセス',['pid'=>'PID','name'=>'名前','username'=>'実行者','status'=>'状態']],
'listeners'=>['待受ポート',['protocol'=>'方式','address'=>'アドレス','port'=>'ポート','scope'=>'範囲','process_name'=>'プロセス']],
'connections'=>['接続',['address'=>'ローカル','port'=>'ポート','remote_address'=>'接続先','remote_port'=>'先方ポート','state'=>'状態']],
] as $key=>[$label,$columns]): $list=$inventory[$key]??[]; $rows=$list['rows']??[]; ?>
<details><summary><?= $e($label) ?> ／ <?= $e($list['status']??'未取得') ?> ／ <?= count($rows) ?>件</summary>
<div class="table-scroll"><table><thead><tr><?php foreach ($columns as $heading): ?><th><?= $e($heading) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $field=>$heading): ?><td><?= $e((string)($row[$field]??'—')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
</details>
<?php endforeach; ?>
</div></section>

<?php $host = $sections['host'] ?? []; $updates = $host['updates'] ?? []; ?>
<section class="card section-gap"><div class="card-h">ファイルの照合・更新候補</div><div class="card-b">
<p>ホストの収集状態 <?= $e($host['status']??'未取得') ?> ／ <?= $e(Time::display($host['captured_at']??null)) ?></p>
<details><summary>監視対象ファイル</summary><ul>
<?php foreach ($host['files']??[] as $file): ?><li><code><?= $e($file['path']) ?></code> ／ <?= $e($file['status']) ?></li><?php endforeach; ?>
</ul></details>
<details><summary>APT のセキュリティ更新候補 <?= (int)($updates['security']??0) ?> 件 ／ <?= $e($updates['status']??'未取得') ?></summary>
<p>キャッシュの更新 <?= $e(Time::display($updates['cache_updated_at']??null)) ?></p>
<ul><?php foreach ($updates['rows']??[] as $package): if (empty($package['security'])) continue; ?>
<li><?= $e($package['name']) ?> ／ <?= $e($package['installed']??'—') ?> → <?= $e($package['candidate']??'—') ?></li>
<?php endforeach; ?></ul>
</details>
</div></section>

<section class="card section-gap"><div class="card-h">日次・週次レポート</div>
<div class="table-scroll"><table><thead><tr><th>期間</th><th>イベント</th><th>アラート</th><th>保存</th></tr></thead><tbody>
<?php foreach ($sections['reports']??[] as $report): ?>
<tr><td><?= $report['kind']==='daily'?'日次':'週次' ?> ／ <?= $e(Time::display($report['period_start'])) ?> 〜 <?= $e(Time::display($report['period_end'])) ?></td>
<td><?= (int)$report['event_total'] ?></td><td><?= (int)$report['alert_total'] ?></td>
<td><a href="<?= $url ?>/reports/<?= rawurlencode($report['id']) ?>">JSON</a></td></tr>
<?php endforeach; ?>
<?php if (empty($sections['reports'])): ?><tr><td colspan="4">保存済みのレポートはありません</td></tr><?php endif; ?>
</tbody></table></div></section>
