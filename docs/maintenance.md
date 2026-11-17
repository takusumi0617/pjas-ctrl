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

### APIと静的画面が別フォルダの場合

`prepared`かつ停止中に、公開フォルダ外のAPI入口を設定できます。フォルダ全体を公開する操作ではありません。

```bash
sudo php8.5 bin/pjas-site-upgrade routing "$PJAS_UPGRADE_ID" \
  --api-front-controller=server/index.php
```

`/api`は固定PHPファイルへ渡し、元のURI・クエリ・認証ヘッダーを維持します。Laravelは`artisan`、`bootstrap/app.php`、公開フォルダの`index.php`から判定して標準のフロントコントローラーへ振り分けます。

### PHP 8.6以降

対象のCLI・FPM・拡張を導入し、互換性を確認してから`--php=8.6`などを指定します。選択肢の追加だけで移行は完了しません。ctrl自身のPHP変更は`bin/pjas-install-features --check --php=8.6`と、影響を確認した`--apply --php=8.6`でサービス定義まで更新し、更新サービスも再登録します。導入済みの管理用バージョンは`/etc/pjas-control/php-version`に保持します。
