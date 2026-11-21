# 更新・保守

[目的別ガイド](../README.md) / [日常操作](usage.md) / [復旧](recovery.md)

ctrl自身の更新と、管理対象サイトのPHP・隔離の移行は別の作業です。

## ctrlをホームから更新する

### 最初の1回

更新機能を含むコードをサーバーへ取得してから、rootで登録します。コードの独自変更がある場合は先に退避・照合してください。

```bash
cd /opt/pjas-ctrl
sudo git pull --ff-only origin main
sudo php8.5 bin/pjas-control-update install --branch=main
sudo systemctl restart pjas-agentd
```

更新元はこのチェックアウトのoriginです。root専用の`/etc/pjas-control/update.json`へ保存し、画面から任意URLやコマンドへ変更することはできません。別のブランチを使う場合は、そのブランチをチェックアウトしたうえで登録時に`--branch=ブランチ名`を指定します。

認証は既定の`--auth=auto`で、GitHub Appが設定済みならAppを優先します。未設定またはAppで取得できない場合は認証なしの公開取得を試します。privateリポジトリはApp認証が成功する必要があります。privateリポジトリはAppの許可対象に追加し、Contents読み取り権限を与えます。必要に応じ登録時に`--auth=github_app`または`--auth=public`を固定できます。PATをURLへ埋め込みません。

### 毎回の更新

1. 公開・バックアップ・移行などの作業を完了させ、停止時間を確保します。
2. ホームの「ctrl自身の更新」でGitHubから更新内容を確認します。
3. 現在と更新先のコミット、変更ファイル、停止の影響を確認します。
4. 現在のパスワードと確認チェックを入力し、確認したコミットへ更新します。確認の有効期限は15分です。
5. 接続復帰後、更新結果とホーム・サービスの状態を確認します。

確認後にブランチが進んでも別コミットへ切り替えません。コードまたは設定が確認後に変わった場合は再確認が必要です。fast-forwardできない履歴や未保存のコード変更は拒否します。ローカルの`agent/config.php`変更は、更新先がそのファイルを変更しない場合に限り維持します。

更新は専用のsystemdサービスで継続します。設定・管理DB・旧コードをroot専用に退避し、管理画面・監視・agent・管理用PHP-FPMを停止します。同じPHP-FPMを使うサイトも一時停止します。停止に連動する稼働中サービスを確認画面に表示し、更新後に元の稼働状態へ戻します。確認後にこの範囲が変わった場合は再確認を求めます。構文検査、コード更新、管理DBの追加スキーマ適用、サービス起動、管理画面のHTTP検査を経て再開します。失敗時は停止中にコード・管理DB・設定を復旧します。復旧も失敗した場合は[復旧ガイド](recovery.md)を使います。

### サーバーで照合する更新

`config.php`、`agent/config.php`、サービス定義、初回準備、安全性設定、更新プログラムとその共通ライブラリが変わる場合は、画面で適用をブロックします。リンク・サブモジュールや既存ファイルへの衝突も拒否します。

設定と管理DB、サービス・root管理領域を退避してから差分を照合し、コードを取得します。サービス配置が必要な変更だけ、次の事前確認・適用を実行します。

```bash
cd /opt/pjas-ctrl
sudo git status --short
sudo git diff -- agent/config.php
sudo php8.5 bin/pjas-install-features --check
# 差分・停止範囲を確認し、サービス配置が必要なときだけ実行
sudo php8.5 bin/pjas-install-features --apply
```

`--apply`はユーザー・権限・Apache・FPM・サービス配置を変更します。通常のコード更新の代わりに毎回実行しません。独自PHP・Apache設定を保った制限設定の更新が必要な環境では、`bin/pjas-security-update --check`で対象を確認し、対応していることを確認してから`--apply`を使います。

更新基盤を変更した場合は、新コードで`bin/pjas-control-update install`を再実行します。通常のコード更新を手動で行った場合はagentを再起動し、OPcacheの設定に応じてPHPを再読込します。管理用PHPを停止・再起動した場合は管理用HTTPも確認してください。

```bash
sudo systemctl restart pjas-agentd
sudo systemctl start pjas-control-web
sudo systemctl status pjas-agentd pjas-control-web php8.5-fpm pjas-monitor.timer --no-pager
sudo php8.5 bin/pjas-sites-check
```

## 更新前後の診断

更新前に配備コミットと主要ユニットの稼働状態を控えます。診断CLIが導入済みなら、更新前後で同じコマンドを使えます。

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-host-check
sudo php8.5 bin/pjas-host-check --component=pjas-cert
```

管理用PHPが8.5以外なら実行時のPHPを合わせます。診断はループバックのログイン画面とサイトの隔離状態を検査します。公開URLからの接続はブラウザーで確認してください。サービスの起動や設定の書き換えは行いません。隔離サイトの指定は任意です。停止中または起動待ちのv2サイトの稼働検査は `SKIP` とし、起動後に再実行します。

終了コードは、失敗がなければ `0`、失敗があれば `1`、引数の誤りは `2`、ctrlの更新中は `75`、root権限なしは `77` です。個々の検査の `WARN` と `SKIP` は終了コード `0` でも表示されます。

## 外部ホストから公開HTTPSを検査する

監視元のホストにこのリポジトリの `bin/pjas-external-check` を配置し、`curl` が使える状態で実行します。

```bash
sh bin/pjas-external-check https://example.org/healthz 200
```

外部の名前解決とTLSの検証を含めて、指定したHTTPステータスを確認します。正常時は終了コード0、接続エラー・ステータス不一致は1、入力エラーは2です。既存の監視ツールからこの終了コードを監視できます。

## 公開設定の影響を確認する

サイトの公開設定画面で、生成された差分、対象ファイルと有効化リンク、無効化される競合設定、Apacheの再読み込みを確認できます。プレビュー後に現在の設定、生成候補、有効化リンク、競合一覧が変わった場合は適用が停止します。画面を開き直し、変更を確認してから適用してください。

## 定期的な構成照合

`pjas-monitor.timer` の定期処理で、各サイトの構成を1時間ごとに照合します。管理画面のホームに、最終照合時刻と差分、検査を見送った理由を表示します。

新たに確認した差分と、一致を確認できた復旧を既存の通知先へ送ります。同じ差分は繰り返し通知しません。サイトの通知設定が無効でも、ホームの通知履歴には記録します。移行・復旧・設定変更中は照合を見送ります。

## 登録済みサイトの構成を照合する

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-host-check --drift
sudo php8.5 bin/pjas-host-check --drift=pjas-cert
```

`--drift` は有効な全登録サイト、`--drift=サイト名` は指定したサイトを検査します。Apacheの生成結果と有効化リンク、通常サイトの専用FPMプール、v2の承認台帳に記録された生成済みsystemdユニットと専用FPM設定を照合します。ctrlが新規作成・再保存した常駐ユニットも承認時のハッシュと照合します。旧版で作成され、照合元を持たないユニットは `SKIP` です。systemdの再読み込み待ちはCLIで警告します。結果はサイト詳細の「実行環境」でも確認できます。

`FAIL` は記録済みファイルの欠落、内容の変更、ファイル種別の異常、読み取り失敗を示します。対象パスと理由を確認し、意図した設定変更かを照合してください。再起動直後には揮発領域の設定が未生成の場合があります。CLIの構成照合はファイルの再生成やサービス起動を行いません。照合元がなければ `SKIP` を表示します。手動管理のdrop-inは照合対象に含めません。

## v2コンポーネントの承認台帳を修正する

管理者所有の `/etc/pjas/components.json` を修正した場合、既存のコンポーネント状態に記録された許可台帳のハッシュが変わります。安全性検証を回避して状態JSONを書き換えず、必ず「実行環境」の構成レビューと適用を経由してください。

1. CA秘密鍵・証明書・管理DBを含む整合性バックアップを確保し、修正前の台帳と設定をroot専用の場所へ退避します。台帳の変更は必要な項目だけに限定します。
2. サイト詳細の「実行環境」で「読み込み済みGitHubコミットのトークン」を入力して「設定の差分を確認」を実行します。トークンはGitHubの`.pjas`読み込みで取得した、現在と同じマニフェストを指すものを使用します。
3. 同じマニフェスト・同じサイトであること、変更するApacheユーザー・FPM設定・公開先が承認内容と一致することを確認します。既存の「起動・停止とバックアップ」から `stop` を選択し、全コンポーネントが停止した後で「確認した内容を適用」を実行します。
4. **マニフェストが同一で、以前に初期化が完了しており、かつ状態が `initialized` / `ready` / `stopped` の場合だけ**、再適用完了後の状態は `initialized` に戻ります。実行ユーザー、秘密情報、永続領域、生成unit/FPM設定、ファイルハッシュの検証は従来どおり行います。再適用でサービスは自動起動しません。
5. 初期化済みのCAに対して「初回セットアップ」や「CA状態の復元」を実行しないでください。変更内容が一致しない場合、初期化状態は自動継承されず、従来の初期化・検証手順が必要です。バックアップ・停止状態・履歴保持を確認したうえで管理者が対処します。
6. 「実行環境」で明示的に `start` を実行し、構成確認済み、各コンポーネントの準備完了、Web応答、証明書管理機能を検証します。承認後のunitやFPM設定を外部スクリプトで上書きしないでください。

設定更新のみを行う場合にも、GitHub上の`.pjas`と承認済みコミットを変更していないことを確認してください。既存のWeb/CA/署名プロセスが稼働中の場合、再適用は意図的に拒否されます。

## サイトのPHP・隔離を移行する

対象PHPのCLI・FPM・拡張を先に導入し、アプリ互換性とバックアップを確認します。以下は単一ユーザーで動く通常サイトの移行です。処理別の構成を持つサイトは「実行環境」のマニフェスト・プロファイル更新を使い、CAを再初期化しません。

### 単独サイト

```bash
cd /opt/pjas-ctrl
PJAS_SITE=example-site
PJAS_UPGRADE_ID="$(sudo php8.5 bin/pjas-site-upgrade plan "$PJAS_SITE" --php=8.5 --id-only)"
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"
```

計画の常駐サービス、cron、標準化されるApache設定、共有FPMへの影響を確認します。外部の書き込み元も停止してから適用します。確認フラグは実際にその条件を満たした場合だけ付けます。

```bash
sudo php8.5 bin/pjas-site-upgrade apply "$PJAS_UPGRADE_ID" \
  --accept-standard-apache --external-processes-stopped
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"
```

適用後も公開停止を維持します。互換性・共有データ・ルーティングを確認し、サイト詳細「実行環境」または同じIDのCLIで検査・再開します。

```bash
sudo php8.5 bin/pjas-site-upgrade activate "$PJAS_UPGRADE_ID" --confirm-activation
sudo php8.5 bin/pjas-sites-check
```

### 複数サイト

```bash
PJAS_BATCH_ID="$(sudo php8.5 bin/pjas-site-upgrade batch-plan site-a,site-b --php=8.5 --id-only)"
sudo php8.5 bin/pjas-site-upgrade batch-status "$PJAS_BATCH_ID"
sudo php8.5 bin/pjas-site-upgrade batch-apply "$PJAS_BATCH_ID" \
  --accept-standard-apache --external-processes-stopped --confirm-activation
```

サイトごとに順番に適用・再開し、失敗したサイトで停止します。完了済みは維持します。`manual`となるコンポーネントサイトは専用操作へ分け、対象外とする場合だけ`--skip-manual`を指定します。静的サイトは静的のまま維持します。

### pjas-chatのFPM環境変数を引き継ぐ

既存の環境変数は管理者が `/etc/pjas/site-pool-env/pjas-chat.json` に登録します。配置先をroot所有・0700、ファイルをroot所有・0600にし、既存の公開鍵はサーバー内で転記してください。

`CF_ACCESS_TEAM` と `CF_ACCESS_AUD` には、それぞれ文字列 `$CF_ACCESS_TEAM` と `$CF_ACCESS_AUD` を指定します。FPMサービス側の環境変数の供給元は既存のものを使います。`PJAS_DB` は `/var/lib/pjas-chat/pjas.sqlite`、`VAPID_PUBLIC_KEY` は実際の公開鍵です。登録後は `sudo php8.5 bin/pjas-host-check --drift=pjas-chat` でFPMプールが一致することを先に確認します。

### APIと静的画面が別フォルダの場合

`prepared`かつ停止中に、公開フォルダ外のAPI入口を設定できます。フォルダ全体を公開する操作ではありません。

```bash
sudo php8.5 bin/pjas-site-upgrade routing "$PJAS_UPGRADE_ID" \
  --api-front-controller=server/index.php
```

`/api`は固定PHPファイルへ渡し、元のURI・クエリ・認証ヘッダーを維持します。Laravelは`artisan`、`bootstrap/app.php`、公開フォルダの`index.php`から判定して標準のフロントコントローラーへ振り分けます。

### PHP 8.6以降

対象のCLI・FPM・拡張を導入し、互換性を確認してから`--php=8.6`などを指定します。選択肢の追加だけで移行は完了しません。ctrl自身のPHP変更は`bin/pjas-install-features --check --php=8.6`と、影響を確認した`--apply --php=8.6`でサービス定義まで更新し、更新サービスも再登録します。導入済みの管理用バージョンは`/etc/pjas-control/php-version`に保持します。

## 設定の管理元

| 対象 | 管理元と変更入口 |
| --- | --- |
| ctrl が管理する公開設定と隔離 PHP-FPM | ctrl に保存したサイト設定。変更前に差分を確認し、ctrl の適用機能を使う |
| v2 コンポーネント | 承認済みのマニフェストとホスト側のポリシー。コンポーネントの専用更新手順を使う |
| 手動管理の VirtualHost・外部の systemd unit | ホスト側の設定または運用者が指定する構成管理。サイトへ関連付けても元の unit の管理は引き継がない |
| Ubuntu のパッケージと OS 設定 | Ubuntu 標準の apt・systemd と運用者の構成管理 |
| ctrl 本体 | 更新元に設定した GitHub リポジトリとブランチ。ホスト固有の設定と管理DBはサーバーで管理する |

ctrl が生成する設定に差分が出た場合は、生成元と稼働設定を照合してから再適用します。
複数サイトを公開前にまとめて準備・復旧する場合は [復旧ガイド](recovery.md#公開前の一括サイト移行を復旧する) を参照してください。

### PHP移行とAPI公開設定を同じ計画に含める

静的な公開ディレクトリの外にPHP APIがあるサイトでは、対象サイトと
リリース内のAPI入口を同じ計画に記録できます。

```bash
sudo php8.5 bin/pjas-site-upgrade batch-plan pjas-vault --php=8.5 \
  --api=pjas-vault:server/index.php
sudo php8.5 bin/pjas-site-upgrade batch-prepare "$PJAS_BATCH_ID" \
  --accept-standard-apache --external-processes-stopped
sudo php8.5 bin/pjas-site-upgrade batch-status "$PJAS_BATCH_ID"
```

`PJAS_BATCH_ID` には最初のコマンドが返したIDを設定します。
準備で移行とAPI設定を適用し、サイトを停止したまま保持します。
確認後に `batch-apply` で公開するか、公開前に `batch-rollback` で設定を戻せます。

コードのデプロイでは、公開前の構文・Apache設定検査と公開後のHTTP検査を行います。
自動切り戻しが有効で元のリリースがある場合は、公開失敗後に前のリリースを
復帰させ、そのHTTP検査結果をジョブに記録します。共有データは別途復旧します。
