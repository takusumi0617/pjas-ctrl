# 管理画面のアイコンと表示

管理画面のアイコンは [Google Material Symbols](https://fonts.google.com/icons) のRounded・24px・塗りつぶしなしに統一しています。全14メニュー、サイトのタブ、ファイル一覧、公開状態スイッチ、主要操作、閉じるボタン、エラーページで共通の描画処理を使います。

## 表示の改善

- アイコンの大きさ、文字との間隔、メニューのクリック領域を統一。
- 選択中のメニューにアクセント線と `aria-current` を付与。
- スマートフォンのメニュー開閉アイコンとラベルを連動し、Escapeキーで閉じてボタンへフォーカスを戻す。
- キーボードのフォーカス枠と「本文へ移動」リンクを追加。
- 明暗テーマに応じてアイコンを文字色に追従させ、長いラベル・メールアドレスの折り返しを調整。

アイコンはリポジトリに同梱したSVGから描画します。画面表示時のGoogleへのアクセスや外部フォントの取得は不要です。表示名はテキストでも残し、装飾用SVGは読み上げ対象から外します。メニューの表示権限と既存の操作は維持します。

## 既存サーバーへの反映

今回の変更には、先に追加したPHP 8.5の選択肢の修正も含まれます。公開やバックアップ等の処理が実行中でない時間に実施してください。

```bash
(
  set -e
  cd /opt/pjas-ctrl
  sudo git pull --ff-only origin main
  sudo systemctl restart pjas-agentd.service
  sudo systemctl is-active pjas-agentd.service
)
```

ブラウザーで管理画面を再読み込みします。CSS・JavaScriptのURLには更新識別子を付けています。PHPのOPcacheをタイムスタンプ確認なしで運用している場合は、管理画面を処理するPHP-FPMもreloadしてください。PHP 8.4/8.5のどちらが処理しているかは `/etc/pjas-control/apache.conf` のFPMソケット指定と各プール設定で確認します。

DB移行、セキュリティ設定の再適用、サイトの再公開は不要です。管理対象サイトのコードやPHPランタイムを自動変更する更新ではありません。

## 開発時の追加方法

`app/Support/Icons.php` の許可一覧と `public/assets/icons/material-symbols.svg` に同じ名前の素材を追加し、`Icons::render('名前')` で描画します。未登録名は拒否します。配布元・固定コミット・ライセンスは同じディレクトリの `NOTICE.md` と `LICENSE` に記録しています。

`tests/ui_icons.php` はメニュー全項目のアイコン、現在位置、権限別表示、同梱素材、不正な名前の拒否を確認します。画面の表示確認には実際のレイアウトとサイト一覧テンプレートを `tests/support/ui_fixture.php` の架空データで描画できます。
