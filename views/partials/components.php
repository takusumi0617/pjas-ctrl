<?php use Pjas\Http\{View,Csrf}; ?>
<p>処理ごとの実行ユーザー・永続領域・秘密情報を管理します。設定更新、コード公開、初回セットアップ、状態復元はそれぞれ独立した操作です。</p>
<?php if($error): ?><p role="alert"><?= View::e($error) ?></p><?php endif; ?>
<?php if($site): ?>
<section class="card section-gap"><div class="card-h">処理ごとの実行環境<span class="spacer"></span><span class="tag <?= !empty($state['configured'])?'t-ok':'t-warn' ?>"><?= !empty($state['configured'])?'構成確認済み':'構成の確認が必要' ?></span></div><div class="table-scroll"><table><thead><tr><th>処理</th><th>稼働・検査</th><th>PHP・実行ユーザー</th><th>書き込み領域</th><th>起動に必要な処理</th></tr></thead><tbody><?php foreach($state['components']??[] as $id=>$c):?><tr><td><?= View::e($id) ?></td><td><span class="tag <?= !empty($c['ready'])?'t-ok':'t-warn' ?>"><?= !empty($c['ready'])?'稼働・検査正常':(!empty($c['active'])?'稼働中・検査待ち':'停止・未確認') ?></span><?php if(!empty($c['restart_required'])):?><small>秘密情報の更新後に再起動が必要</small><?php endif;?></td><td><?= View::e($c['fpm_binary']??'個別の実行コマンド') ?><small><?= View::e($c['user']??$c['profile']) ?></small></td><td><?= View::e(implode('、',$c['storage']??[])) ?></td><td><?= View::e(implode('、',$c['depends_on']??[]) ?: 'なし') ?></td></tr><?php endforeach;?><?php if(empty($state['components'])):?><tr><td colspan="5">適用済みの処理はありません。構成の読み込み・依存環境を確認してください。</td></tr><?php endif;?></tbody></table></div><div class="card-b"><p>現在のコード：<?= View::e($state['current']??'未公開') ?></p><details><summary>詳細な診断情報</summary><pre><?= View::e(json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details></div></section>
<section class="card section-gap">
  <div class="card-h">承認済み構成との照合</div>
  <div class="card-b">
    <?php if (!empty($state['configuration_recovery'])): ?>
      <p role="alert">設定の復旧が必要です。SSHで <code>sudo php8.5 /opt/pjas-ctrl/bin/pjas-component-recover <?= View::e($site['name']) ?> <?= View::e($state['configuration_recovery']) ?></code> を実行してください。</p>
    <?php endif; ?>
    <?php if (empty($state['drift'])): ?>
      <p>照合対象の生成済み設定がありません。</p>
    <?php else: ?>
      <ul>
      <?php foreach ($state['drift'] as $row): ?>
        <li><code><?= View::e($row['path']) ?></code> — <?= View::e($row['detail']) ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>">
<label>読み込み済みGitHubコミットのトークン <input name="token" pattern="[a-f0-9]{32}" required></label>
<button class="btn" name="operation" value="components.plan">設定の差分を確認</button><button class="btn" name="operation" value="components.release">コード公開を確認</button>
<p>GitHubの.pjas読み込みで取得した固定コミットを使います。確認だけではコードや設定を適用しません。</p></form>
<?php if($review): $next=['components.webplan'=>'components.webapply','components.plan'=>'components.apply','components.release'=>'components.publish','components.restoreplan'=>'components.restore','tls.plan'=>'tls.apply'][$review['operation']]??null; ?>
<h2>適用前の確認</h2><pre><?= View::e(json_encode($review['result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre>
<?php if($next): ?><form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><input type="hidden" name="operation" value="<?= View::e($next) ?>"><input type="hidden" name="review" value="<?= View::e($review['result']['review']) ?>"><?php foreach(['provider','backup'] as $key): if(isset($review['input'][$key])): ?><input type="hidden" name="<?= $key ?>" value="<?= View::e($review['input'][$key]) ?>"><?php endif; endforeach; ?><label>現在のパスワード <input type="password" name="password" autocomplete="current-password" required></label><button class="btn">確認した内容を適用</button></form><?php endif; endif; ?>
<form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><button class="btn" name="operation" value="components.webplan">サイト別FPMの公開設定を確認</button></form>
<h2 class="process-heading">起動・停止とバックアップ</h2>
<form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><label>現在のパスワード <input type="password" name="password" autocomplete="current-password" required></label><button class="btn" name="operation" value="components.backup">整合性バックアップを作成</button><label>稼働操作 <select name="action"><option>start</option><option>stop</option><option>restart</option></select></label><button class="btn" name="operation" value="components.action">実行</button></form>
<h2>コードだけを切り戻す</h2><form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><input type="hidden" name="operation" value="components.rollback"><input type="hidden" name="expected_current" value="<?= View::e($state['current']??'') ?>"><label>保存済みリリース <select name="release"><?php foreach($state['releases']??[] as $tag=>$r): ?><option><?= View::e($tag) ?></option><?php endforeach; ?></select></label><label>現在のパスワード <input type="password" name="password" autocomplete="current-password" required></label><button class="btn">コードを切り戻す（CA状態は維持）</button></form>
<details class="runtime-details"><summary>初期セットアップ・CA状態の復元（管理者向け）</summary><div class="note">PHP更新やコード公開では初期化しません。CA状態の復元は履歴に影響するため、バックアップと停止範囲を確認してください。</div><form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><label>現在のパスワード<input type="password" name="password" autocomplete="current-password" required></label><button class="btn" name="operation" value="components.setup">初回セットアップを確認・実行</button></form><h2>CA状態の復元</h2><form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><label>バックアップID <input name="backup" required></label><button class="btn" name="operation" value="components.restoreplan">復元の影響を確認</button></form>
<p>復元後は発行停止を維持します。検査を終え、発行・失効履歴の欠落を確認してから停止状態を解除してください。</p>
<form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><input type="hidden" name="operation" value="components.resume"><label><input type="checkbox" name="accept_history_loss" value="1" required>履歴の欠落と二重稼働がないことを管理者として確認した</label><label>現在のパスワード <input type="password" name="password" autocomplete="current-password" required></label><button class="btn">復元後の停止状態を解除</button></form>
</details><h2 class="process-heading">サイトのTLS証明書</h2><form method="post" action="/services/<?= View::e($site['name']) ?>/components"><?= Csrf::field() ?><input type="hidden" name="service" value="<?= View::e($site['name']) ?>"><label>許可済み証明書プロバイダーID <input name="provider" required></label><button class="btn" name="operation" value="tls.plan">更新・適用を確認</button></form>
<?php endif; ?>
