<?php
use Pjas\Http\View;
use Pjas\Http\Csrf;
?>
<dialog id="pause-dialog" class="pause-dialog" aria-labelledby="pause-title">
  <div class="dialog-heading"><div><div class="dialog-eyebrow">SITE AVAILABILITY</div><h2 id="pause-title">公開の一時停止・再開</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="閉じる">×</button></div>
  <p class="muted"><?= View::e($s['name']) ?> · <?= View::e($s['domain']) ?></p>
  <form method="post" action="/services/<?= $name ?>/pause">
  <?= Csrf::field() ?><input type="hidden" name="action" value="pause">
    <div class="field"><label for="pause-code">停止中に返すHTTPコード</label>
      <?php
      $codeGroups = [
          '成功を表すコード' => [
              200 => '正常に表示する', 201 => '作成が完了した', 202 => '処理を受け付けた',
              203 => '別のサーバーを経由した情報を返す', 204 => '正常終了・本文なし',
              205 => '正常終了・入力画面をリセット（本文なし）', 206 => 'データの一部を返す',
              207 => '複数の処理結果を返す', 208 => '同じ結果を報告済み', 226 => '変更した部分のデータを返す',
          ],
          '転送・キャッシュに関するコード' => [
              300 => '複数の移動先候補がある', 301 => '移動先へ恒久的に転送する',
              302 => '別の場所へ一時的に転送する', 303 => '処理結果を別の場所で表示する',
              304 => '前回から変更なし（本文なし）', 305 => '指定の中継サーバーを使う（現在は非推奨）',
              307 => '送信方法を保ったまま一時的に転送する', 308 => '送信方法を保ったまま恒久的に転送する',
          ],
          'アクセス・リクエストに関するエラー' => [
              400 => 'リクエストの内容が正しくない', 401 => 'ログインなどの認証が必要',
              402 => '支払いが必要', 403 => 'アクセスを許可しない', 404 => 'ページが見つからない',
              405 => 'この送信方法は使えない', 406 => '希望された形式では返せない',
              407 => '中継サーバーでの認証が必要', 408 => 'リクエストの受信が時間切れ',
              409 => '現在のデータと処理内容が競合している', 410 => 'ページは削除済み・今後も戻らない',
              411 => '送信データの長さの指定が必要', 412 => '実行するための条件を満たしていない',
              413 => '送信データが大きすぎる', 414 => 'URLが長すぎる', 415 => '未対応のデータ形式',
              416 => '指定されたデータ範囲を返せない', 417 => '要求された条件に対応できない',
              418 => 'ジョーク用のコード（私はティーポット）', 421 => '接続先のサーバーが違う',
              422 => 'データの意味や内容を処理できない', 423 => '対象がロックされている',
              424 => '関連する別の処理が失敗した', 425 => '再送の危険があるため処理を保留',
              426 => '新しい通信方式への切り替えが必要', 428 => '実行条件の指定が必要',
              429 => '短時間のアクセスが多すぎる', 431 => 'リクエストのヘッダーが大きすぎる',
              451 => '法的な理由により表示できない',
          ],
          'サーバー側のエラー' => [
              500 => 'サーバー内部で問題が発生した', 501 => 'この機能には対応していない',
              502 => '接続先のサーバーから正常な応答がない', 503 => '一時停止・メンテナンス中（おすすめ）',
              504 => '接続先のサーバーの応答が時間切れ', 505 => 'このHTTPのバージョンには対応していない',
              506 => '返すデータ形式の選択設定に問題がある', 507 => '保存容量が不足している',
              508 => '処理が繰り返し続けている', 510 => 'リクエストの追加拡張が必要（現在は廃止）',
              511 => 'ネットワーク利用のための認証が必要',
          ],
      ];
      $knownCodes = [];
      foreach ($codeGroups as $codes) $knownCodes += $codes;
      ?>
      <select id="pause-code" name="code" required>
        <?php foreach ($codeGroups as $group => $codes): ?>
          <optgroup label="<?= View::e($group) ?>">
          <?php foreach ($codes as $code => $meaning): ?>
            <option value="<?= $code ?>" <?= (int) $pause['code'] === $code ? 'selected' : '' ?>><?= $code ?> — <?= View::e($meaning) ?></option>
          <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
        <?php if (!isset($knownCodes[(int) $pause['code']])): ?>
          <option value="<?= (int) $pause['code'] ?>" selected><?= (int) $pause['code'] ?> — 標準では定義されていないコード（保存済み）</option>
        <?php endif; ?>
      </select>
      <div class="hint">通常の一時停止には503、ページが存在しないように表示する場合は404を選びます。説明は各コードの通常の意味です。ここでは番号と入力HTMLを返し、ログイン要求や別URLへの転送処理は追加しません。204・205・304はHTML本文を表示しません。</div>
    </div>

    <div class="field"><label for="pause-page-mode">停止中に表示するページ</label><select id="pause-page-mode" name="page_mode">
      <option value="default" <?= ($pause['page_mode'] ?? 'custom') === 'default' ? 'selected' : '' ?>>ctrlの既定ページを使う</option>
      <option value="custom" <?= ($pause['page_mode'] ?? 'custom') !== 'default' ? 'selected' : '' ?>>独自のHTMLを使う</option>
    </select></div>
    <div class="default-page-note" id="pause-default-note" <?= ($pause['page_mode'] ?? 'custom') !== 'default' ? 'hidden' : '' ?>><span class="page-icon" aria-hidden="true">◇</span><div><strong>HTTPコードに合わせた案内ページ</strong><div class="hint">画面サイズ・ダークモードに対応した、ctrl既定のデザインで表示します。</div></div></div>
    <div class="field" id="pause-custom" <?= ($pause['page_mode'] ?? 'custom') === 'default' ? 'hidden' : '' ?>><label>表示するHTML</label><textarea name="html" rows="7" class="mono" <?= ($pause['page_mode'] ?? 'custom') === 'default' ? 'disabled' : '' ?>><?= View::e($pause['html']) ?></textarea><div class="hint">CSS・JavaScriptはインラインまたは外部URLで指定できます。PHPは実行しません。</div></div>
    <div class="dialog-actions"><button type="button" class="btn" data-close-dialog>キャンセル</button><button class="btn p"><?= !empty($pause['enabled']) ? '停止ページを更新' : '一時停止する' ?></button></div>
  </form>
  <?php if (!empty($pause['enabled'])): ?><form method="post" action="/services/<?= $name ?>/pause" class="resume-form"><?= Csrf::field() ?><input type="hidden" name="action" value="resume"><span class="hint">通常のサイト表示に戻します。</span><button class="btn">公開を再開する</button></form><?php endif; ?>
</dialog>
