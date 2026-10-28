# .pjas による一括登録（version 1）

公開GitHubリポジトリのルートに `.pjas` という名前のJSONファイルを置きます。新規登録画面の取得元で「公開GitHubリポジトリ」を選び、「.pjasから設定を読み込む」にチェックして「設定を読み込んで確認する」を押すと、サイト設定が入力され、JSONとApache設定全文を確認できます。チェックしたまま通常の登録ボタンから進んだ場合も、先に確認画面を表示します。チェックを外した場合は `.pjas` を読み込まず、以前読み込んだ内容も適用しません。読み込みだけではサイトや常駐プログラムを登録・実行しません。

設定を確認し、ドメインなどを修正し、確認チェックを付けて登録します。サイトの通常項目（名称・ドメイン・PHP・公開フォルダ・共有パス・準備処理）は画面の入力欄が優先です。JSONではcronや常駐プログラムを編集できます。確認済みのコミットをキャッシュし、ブランチが進んでも別のコードを実行しません。Apache設定ファイルの変更はリポジトリ側で修正してから再読み込みします。

実例は [examples/pjas-chat/.pjas](../examples/pjas-chat/.pjas) と [Apache設定](../examples/pjas-chat/deploy/apache.conf) を参照してください。これらを対象リポジトリのルートと deploy/ に配置します。

| 項目 | 内容 |
|---|---|
| version | 現在は整数の1のみ |
| site.name | 初期サイト名。登録画面で変更可能 |
| site.domain | 任意。未指定ならconfのServerNameを初期値にし、画面で入力 |
| site.runtime | php8.4-fpm / php8.3-fpm / php8.2-fpm / php8.1-fpm / static |
| site.document_root | リポジトリ内の公開フォルダ。省略時public |
| site.https | true/false。省略時true |
| site.health_path / health_status | 動作確認。省略時 / と200 |
| apache.file | リポジトリ内のconfの相対パス |
| apache.source_root | 元のプロジェクトパス。省略時 /var/www/site.name |
| shared | バージョン間で引き継ぐ相対パスの配列 |
| prepare | 既存の許可一覧にある準備コマンドの配列 |
| daemons | name・label・command・working_directory・start_on_install・start_on_boot |
| cron | name・label・schedule・command・enabled |

## Apache設定の継承

通常のVirtualHostを含むconf全文に対応します。VirtualHost内だけの設定断片も利用でき、その場合は管理機能がVirtualHostを追加します。独自ディレクティブ・WebSocket・API・アクセス制限などを保持します。パスは `/var/www/pjas-chat/dist` のように書けます。

`apache.source_root` は `/…/www/プロジェクト名` の形式に対応します。指定した元パスを登録先サイトへ置き換えた上で、DocumentRootと関連パスをcurrent配下へ、証明書と秘密鍵を共通のCloudflare証明書へ変更します。sharedは登録先のsharedへ維持します。ServerNameは画面で指定したドメインに置き換えます。別のドメインを持つ複数サイトを同居させたconfには対応しません。

サーバー上の既存confと違い、GitHubのファイルは未適用の設定です。Include・モジュール読み込み・パイプ式ログ・継続行・サーバー全体の設定は受け付けません。VirtualHostの外側はコメントとIfModuleの囲みのみ許可します。内容とモジュールの導入状態は管理者が確認し、反映時にはconfigtestを行います。必要なApacheモジュールの自動インストールは行いません。

## 常駐プログラム・定期処理

すべて登録先サイトに関連付け、既存の非特権ユーザー設定（通常www-data）で実行します。作業フォルダはcurrentが基準です。サイト内の直置きパス・日時付きリリースパスはcurrentへ正規化し、`{{site_root}}` も使えます。自動起動・初回起動は明示的にtrueを指定したときだけ設定します。cron.enabledの省略時はtrueです。

初回セットアップの再試行では、登録済みのデーモンを重複作成しません。途中で失敗した場合は作成済みの設定が残ることがあります。作業ログと「常駐プログラム」「定期処理」で確認できます。以後の通常公開で`.pjas`を自動再適用・自動削除しないため、ctrlで編集した設定を保持します。設定更新の差分取り込みは今後の拡張対象です。

GitHubから既存サービスへの自動関連付けは行いません。既存デーモンがある場合は `.pjas` の新規作成指定を外し、ctrlで既存サービスに関連付けて二重起動を避けてください。

APIキー・パスワードはGitに記載せず、環境変数ファイルなどを別途設定してください。この例はpjas-chatの環境変数・Cloudflare Access・DB設定まで代行するものではありません。既存のpjas-websocket.serviceから環境変数設定を引き継ぎたい場合は、新規デーモン作成を外して既存サービスを関連付けます。

## 運用

追加DBスキーマはありません。cronテーブルのない古い環境のみpjas-migrateが必要です。取得キャッシュと初回設定台帳は `/var/lib/pjas-repositories` にroot所有で保存します。成功した初回セットアップの取得キャッシュは削除します。未確定プレビュー・失敗したプレビューは残るため、不要になったものを管理者が削除してください。進行中の初回セットアップに使うキャッシュは削除しないでください。
