<?php
use Pjas\Http\{Csrf, View};
use Pjas\Support\Time;
$e = View::e(...);
$url = '/server/security-events';
?>
<p><a href="/server">サーバーとctrlに戻る</a></p>
<h1>pjas-security</h1>
<?php if ($error !== ''): ?><p role="alert"><?= $e($error) ?></p><?php endif; ?>
<?php if (!$configured): ?>
<p>管理者がサーバー設定に pjas-security の HTTPS オリジンを登録すると利用できます。</p>
<?php elseif (!$connected): ?>
<section class="card"><div class="card-h">pjas-security に接続</div><div class="card-b">
<form method="post" action="<?= $url ?>"><?= Csrf::field() ?>
<label>ユーザー名 <input name="username" autocomplete="username" required maxlength="64"></label>
<label>pjas-security のパスワード <input name="password" type="password" autocomplete="current-password" required></label>
<label>認証コード <input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" required></label>
<button class="btn" name="action" value="login">接続</button>
</form></div></section>
<?php else: ?>
<p><a href="<?= $url ?>/observations">アラート・プロセス・ポート・レポート</a></p>
<form method="post" action="<?= $url ?>"><?= Csrf::field() ?><button class="btn" name="action" value="logout">接続を解除</button></form>
<section class="card section-gap"><div class="card-h">検知イベント</div><div class="card-b">
<form method="get" action="<?= $url ?>">
<label>サイト <select name="site"><option value="">すべて</option>
<?php foreach ($sites ?? [] as $name=>$domain): ?>
<option value="<?= $e($name) ?>" <?= ($filters['site']??'')===$name?'selected':'' ?>><?= $e($name) ?></option>
<?php endforeach; ?></select></label>
<label>内容 <input name="query" maxlength="100" value="<?= $e($filters['query']??'') ?>"></label>
<label>分類 <input name="category" maxlength="32" value="<?= $e($filters['category']??'') ?>"></label>
<label>重要度 <input name="severity" maxlength="16" value="<?= $e($filters['severity']??'') ?>"></label>
<label>送信元IP <input name="ip" maxlength="64" value="<?= $e($filters['ip']??'') ?>"></label>
<button class="btn">絞り込む</button></form>
</div><div class="table-scroll"><table>
<thead><tr><th>時刻</th><th>分類・重要度</th><th>内容</th><th>サイト</th><th>送信元IP</th></tr></thead>
<tbody>
<?php foreach ($events as $event): ?>
<tr><td><?= $e(Time::display($event['timestamp_utc'])) ?></td>
<td><?= $e($event['category']) ?> ／ <?= $e($event['severity']) ?></td>
<td><?= $e($event['title']) ?><small><?= $e($event['id']) ?></small></td>
<td><?php if ($event['site']!==null): ?><a href="/services/<?= rawurlencode($event['site']) ?>"><?= $e($event['site']) ?></a><?php else: ?>—<?php endif; ?></td>
<td><?= $e($event['source_ip']??'不明') ?></td></tr>
<?php endforeach; ?>
<?php if (!$events): ?><tr><td colspan="5">このページに該当するイベントはありません。</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($next): ?><div class="card-b"><a href="<?= $e($url.'?'.http_build_query($filters+['cursor'=>$next])) ?>">次のページ</a></div><?php endif; ?>
</section>

<section class="card section-gap"><div class="card-h">防御サービス</div><div class="card-b">
<p>状態 <?= $e($defense['status'] ?? '未取得') ?> ／ 同期 <?= $e($defense['sync']['status'] ?? '未確認') ?></p>
<?php if (($defense['status']??'')==='ok' && !empty($defense['enabled'])): ?>
<form method="post" action="<?= $url ?>"><?= Csrf::field() ?>
<label>操作 <select name="operation"><option value="block">一時遮断</option><option value="unblock">解除</option></select></label>
<label>IPアドレス <input name="ip" maxlength="64" required></label>
<label>遮断時間（秒） <input type="number" name="duration" min="60" max="3600" value="300"></label>
<label>理由 <input name="reason" maxlength="300" required></label>
<label>根拠イベントID <input name="evidence_id" maxlength="80"></label>
<button class="btn" name="action" value="propose">申請を作成</button>
</form>
<?php endif; ?>
</div></section>

<?php if (($defense['status']??'')==='ok'): ?>
<section class="card section-gap"><div class="card-h">遮断・解除の申請</div><div class="card-b">
<?php foreach (array_slice($defense['proposals']??[],0,50) as $proposal): $content = $proposal['content']; ?>
<details><summary><?= $e($content['ip']) ?> ／ <?= $content['action']==='block'?'一時遮断':'解除' ?> ／ <?= $e($proposal['status']) ?></summary>
<dl>
<dt>範囲</dt><dd><?= $e($content['scope']??'') ?></dd>
<dt>期間</dt><dd><?= (int)$content['duration'] ?> 秒</dd>
<dt>理由</dt><dd><?= $e($content['reason']) ?></dd>
<dt>根拠ID</dt><dd><?= $e($content['evidence_id']?:'なし') ?></dd>
<dt>申請ID</dt><dd><?= $e($proposal['id']) ?></dd>
<dt>内容ハッシュ</dt><dd><code><?= $e($proposal['digest']) ?></code></dd>
</dl>
<?php if ($proposal['status']==='pending' && !empty($defense['enabled'])): ?>
<form method="post" action="<?= $url ?>"><?= Csrf::field() ?>
<input type="hidden" name="proposal" value="<?= $e($proposal['id']) ?>">
<input type="hidden" name="digest" value="<?= $e($proposal['digest']) ?>">
<label>ctrl の現在のパスワード <input type="password" name="ctrl_password" autocomplete="current-password" required></label>
<label>pjas-security のパスワード <input type="password" name="password" autocomplete="current-password" required></label>
<label>pjas-security の認証コード <input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" required></label>
<button class="btn" name="action" value="approve">表示内容を承認</button>
<button class="btn" name="action" value="reject" formnovalidate>却下</button>
</form>
<?php endif; ?></details>
<?php endforeach; ?>
</div></section>

<section class="card section-gap"><div class="card-h">有効な遮断</div><div class="card-b"><ul>
<?php foreach ($defense['blocks']??[] as $block): ?>
<li><?= $e($block['ip']) ?> ／ <?= $e(Time::display(gmdate('c',(int)$block['expires_at']))) ?> まで ／
<?= in_array($block['ip'],$defense['sync']['observed']??[],true)&&($defense['sync']['status']??'')==='synchronized'?'適用を観測':'適用未確認' ?></li>
<?php endforeach; ?>
</ul></div></section>

<section class="card section-gap"><div class="card-h">防御サービスの監査記録</div>
<div class="table-scroll"><table><thead><tr><th>時刻</th><th>結果</th><th>実行者</th><th>申請ID</th></tr></thead><tbody>
<?php foreach (array_slice($defense['audit']??[],0,50) as $entry): ?>
<tr><td><?= $e(Time::display(gmdate('c',(int)$entry['at']))) ?></td><td><?= $e($entry['kind']) ?></td><td><?= $e($entry['actor']) ?></td><td><?= $e($entry['proposal_id']??'') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php endif; ?>
