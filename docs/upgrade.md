# 更新と復旧の確認

[ドキュメント一覧](README.md) / [新規導入](install.md) / [日常運用](sites.md)

すでに導入済みのサーバー向けです。コードの更新、DBの追加スキーマ、管理画面用サービスの更新を扱います。サイトのコード公開、共有データの復元、CA状態の復元は、それぞれ別の操作です。

2026年11月14日の診断対応として、PHP・Apacheの独自設定を維持して制限設定と起動時復帰だけを反映する場合は、先に [診断対応の限定更新](security-update-2026-11-14.md) を使用してください。以下の全機能更新とは適用範囲が異なります。

## 1. 作業時間と退避対象を確認する

公開・切り戻し・削除・バックアップ・初期化が実行中でないことを、作業履歴で確認します。監視タイマーと管理画面・agentを停止する時間を確保してください。初回準備コマンドはApacheの実行ユーザーをpjas-apacheへ分離し、ApacheとPHP 8.5 FPMを再起動するため、同じFPMを使う本番サイトへの影響も考慮します。

標準の保存先は次のとおりです。独自パスは `config.php` と `agent/config.php` で確認します。

| 対象 | 標準の場所 |
| --- | --- |
| Web設定・agent設定 | `/opt/pjas-ctrl/config.php`、`/opt/pjas-ctrl/agent/config.php` |
| 管理DB・二段階認証等の暗号鍵 | `/var/lib/pjas` |
| GitHub App・通知・バックアップ等のroot管理情報 | `/var/lib/pjas-control` |
| GitHub取得元・初回設定、デーモン、一時停止状態 | `/var/lib/pjas-repositories`、`/var/lib/pjas-daemons`、`/var/lib/pjas-pauses` |
| Apache、FPM、サービス定義 | `/etc/apache2`、`/etc/php/8.5/fpm`、`/etc/pjas-control`、`/etc/systemd/system/pjas-*` |
| v2の管理者台帳・保護領域 | `/etc/pjas/components.json` と、管理者が割り当てた領域 |

退避物には秘密情報が含まれます。root専用の場所へ置き、長期保存・別ホストへの保管では暗号化してください。サイトの共有データ用バックアップだけで、ctrl自身やCAの状態を復旧できるわけではありません。

## 2. 管理処理を停止し、設定・DBを退避する

以下は標準パスの例です。`PJAS_BACKUP_DIR` は以降のコマンドでも使用するため、同じシェルで進めます。

```bash
cd /opt/pjas-ctrl
sudo git status --short
sudo git rev-parse HEAD
sudo systemctl stop pjas-monitor.timer pjas-monitor.service pjas-control-web.service pjas-agentd.service
PJAS_BACKUP_DIR="/var/backups/pjas-ctrl/$(date -u +%Y%m%dT%H%M%SZ)"
sudo install -d -o root -g root -m 0700 "$PJAS_BACKUP_DIR"
sudo cp config.php "$PJAS_BACKUP_DIR/web-config.php"
sudo cp agent/config.php "$PJAS_BACKUP_DIR/agent-config.php"
sudo git rev-parse HEAD | sudo tee "$PJAS_BACKUP_DIR/commit.txt" >/dev/null
sudo php8.5 -r '$src = new SQLite3("/var/lib/pjas/pjas.db"); $dst = new SQLite3($argv[1]); if (!$src->backup($dst)) { exit(1); } $dst->close(); $src->close();' "$PJAS_BACKUP_DIR/pjas.db"
```

SQLiteは単純なファイルコピーではなく、バックアップAPIを使います。上の退避は設定と管理DBだけです。表に挙げたroot管理情報やサービス設定も、利用しているバックアップ手段で退避してください。保存に失敗した場合は、その原因を直してから更新へ進みます。

## 3. コードを更新し、独自設定を照合する

`config.php` はGit管理対象外ですが、**`agent/config.php` はGit管理対象**です。独自設定を変更したまま `git pull` すると競合・更新拒否になることがあります。退避済みの設定を確認してから進めます。

```bash
sudo test -s "$PJAS_BACKUP_DIR/agent-config.php"
sudo git diff --name-only
sudo git diff -- agent/config.php
```

`agent/config.php` 以外のソース変更や未追跡ファイルがある場合は、先に変更内容と保存方法を確認してください。以下の `git restore` は、退避済みのagent設定だけを一時的に元の版へ戻す操作です。他の変更をまとめて破棄する操作は行いません。

```bash
sudo test -s "$PJAS_BACKUP_DIR/agent-config.php" && sudo git restore --source=HEAD -- agent/config.php
sudo git pull --ff-only origin main
sudo diff -u "$PJAS_BACKUP_DIR/agent-config.php" agent/config.php
sudo editor agent/config.php
```

`main` は標準の例です。別の運用ブランチを使っている場合は、そのブランチを指定してください。`diff` は差分があれば終了コード1になります。新しい設定項目を残しつつ、退避した環境固有の値をエディターで反映します。旧ファイルを丸ごとコピーして、新しい項目を消さないようにします。

## 4. 事前確認と適用を行う

```bash
sudo php8.5 bin/pjas-install-features --check
sudo php8.5 bin/pjas-install-features --apply
```

管理画面のApache設定が `pjas-ctrl.conf` 以外なら、両方に `--site=設定名` を付けます。コードやDB・ログの配置先を変えている場合は、systemdのパス設定も確認してください。

`--check` は前提条件と変更予定の確認で、全機能の動作保証ではありません。`--apply` は次を実施します。

- 設定と既存DBを `/var/lib/pjas-control/setup-*` に退避する。
- 専用ユーザー、管理画面用FPM設定・HTTPサービス、agent、監視タイマーを配置する。
- 管理DB・ソケットへのアクセス権限を分離し、追加DBスキーマを適用する。
- 管理画面のApache設定を転送方式へ変更する。既存のHTTPS・アクセス元制限は維持する。
- 既存サイトのApache設定に、機密ファイルの公開防止設定を追加する場合がある。
- 設定検査後にagent・PHP-FPMを再起動し、管理画面サービスと監視タイマーを起動する。

独自のsystemd drop-inやApache設定も含め、適用前後の差分を確認してください。失敗時の設定・権限の復旧処理はありますが、追加したDBテーブル・ユーザーなどまで自動で削除するものではありません。

## 5. 更新後の確認

```bash
systemctl status pjas-agentd pjas-control-web php8.5-fpm pjas-monitor.timer --no-pager
sudo -u pjas-control php8.5 /opt/pjas-ctrl/bin/pjasctl services
sudo apachectl configtest
```

管理画面へログインし直し、既存サイトが表示されること、agentが稼働中であること、「運用状況」の最終確認時刻が更新されることを確認します。本番サイトのHTTP応答も確認してください。

v2サイトは、許可台帳・保護領域・アダプターに変更がある場合、[v2の更新・検証手順](pjas-cert.md) も実施します。DBやCA状態の復元を、コード更新の一部として自動実行しないでください。

## 問題が起きた場合

まず失敗した段階とログを確認します。

```bash
sudo journalctl -u pjas-agentd -n 100 --no-pager
sudo journalctl -u pjas-control-web -n 100 --no-pager
sudo journalctl -u pjas-monitor.service -n 100 --no-pager
sudo tail -n 100 /var/log/pjas/control-web-error.log
sudo apachectl configtest
sudo php-fpm8.5 --test
```

| 症状 | 確認するもの |
| --- | --- |
| `--check` で停止する | PHP 8.5/FPM・拡張・Python等の有無、Apache設定名、コードの配置先 |
| 管理画面へ接続できない | ApacheのServerName・証明書・アクセス元制限、専用HTTPサービス、FPM |
| agentへ接続できない | `agent_socket` と `socket` の一致、agentのログ、`pjas-control` の `pjas` グループ所属 |
| DB・ログを書き込めない | ファイル所有者、親ディレクトリの権限、systemdの `ReadWritePaths` |
| 監視時刻が更新されない | `pjas-monitor.timer` と実行サービスのログ、DBのマイグレーション |

初回準備の退避先は、コマンドの出力に表示されます。復旧が必要な場合は、その退避内容と手順2のバックアップを確認し、コード・設定・DB・権限のどこを戻すかを決めます。旧DBを稼働中の環境へ上書きしたり、旧コードへ戻せばDBも戻ったと判断したりしないでください。

コードの更新を伴わず追加テーブルだけを反映する場合は、対象版のコードを確認したうえで `sudo -u pjas-control php8.5 /opt/pjas-ctrl/bin/pjas-migrate` を使います。これはデータ復元やサービス更新の代わりにはなりません。

全サイトのPHP移行は管理画面の保存だけでは完了しません。[サイト隔離とPHPアップグレード](site-isolation.md)に沿ってサイト別に切り替え、旧プール・常駐処理・cronまで確認してください。

## 運用UIの更新（2026-11-17）

通常のコード更新後、`sudo systemctl restart pjas-agentd` で新しい読み取り用の状態取得と移行再開RPCを読み込みます。DBの追加マイグレーションは不要です。ブラウザーを再読み込みしてください。

- ホーム・一覧・サイト詳細は同じ状態判定を使い、3分を超えた確認結果、状態取得失敗、公開停止を正常件数から除外します。ホームは要対応のサイトを先に表示し、以前の運用状況画面はホームへ転送します。
- 常駐処理とcronはサイト詳細の「常駐・定期処理」へ集約しました。関連付け解除はサービス定義・上書き設定・稼働状態を維持し、ctrlの管理台帳からのみ除外します。解除後も実際の処理は停止しません。
- 「実行環境」にはroot専用journalの公開用要約を表示します。準備済み・停止中の移行は、管理者のパスワードと確認を経て既存CLIと同じHTTP検査・公開再開を実行します。初期の計画・隔離適用・切り戻しは引き続きCLIを使います。
- 設定の未確認状態は管理DBに保存し、標準の専用環境では公開設定タブの差分を確認して反映できます。従来の標準エラーページだけの更新も保持しています。未隔離・継承設定を持つサイトの全設定反映は拒否します。
- 管理用PHPの画面からの再起動では、元々稼働していた管理用HTTPも起動・確認します。元々停止中だったHTTPを自動公開しません。

ログの再接続では受信済みの内容とバイト位置を維持し、作業の状態と接続の状態を分けて表示します。画面上の古い待機ジョブを自動で失敗扱いにはせず、記録とプロセスを照合する案内を表示します。
