# pjas-ctrl 導入手順 (Debian / Ubuntu + Apache 2.4)

## 1. 必要パッケージ

Ubuntu 24.04 で PHP 8.4 を使う場合は、PHP パッケージ用 PPA を追加する。

```bash
sudo apt update
sudo apt install -y software-properties-common ca-certificates
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install apache2 php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring unzip curl util-linux cron git
sudo a2enmod rewrite proxy_fcgi setenvif ssl headers
sudo a2enconf php8.4-fpm
sudo systemctl enable --now cron
```

## 2. 配置とユーザー

```bash
sudo git clone <this repo> /opt/pjas-ctrl
sudo groupadd -f pjas
sudo usermod -aG pjas www-data          # web が agent ソケットに接続できるように

sudo install -d -o www-data -g pjas -m 0770 /var/lib/pjas /var/lib/pjas/uploads
sudo install -d -o root    -g pjas -m 0750 /var/log/pjas /var/log/pjas/jobs
sudo chown -R root:root /opt/pjas-ctrl
sudo chmod -R go-w /opt/pjas-ctrl
```

管理画面のコードは root 所有・www-data からは読み取り専用にする (web からコードを書き換えられないこと)。

## 3. 設定

```bash
sudo cp /opt/pjas-ctrl/config.php.example /opt/pjas-ctrl/config.php
sudo chown root:www-data /opt/pjas-ctrl/config.php && sudo chmod 640 /opt/pjas-ctrl/config.php
sudo editor /opt/pjas-ctrl/config.php        # db / socket / www_root を環境に合わせる
sudo editor /opt/pjas-ctrl/agent/config.php  # Cloudflare 証明書、git_repos、web_user を設定
```

`config.php` (web) と `agent/config.php` (agent) の `www_root` / `upload_dir` / `job_dir` /
ソケットパスは必ず一致させる。

## 4. DB 初期化と管理ユーザー

```bash
cd /opt/pjas-ctrl
sudo -u www-data php bin/pjas-init ops@example.com
```

## 5. agent の起動

```bash
sudo cp systemd/pjas-agentd.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now pjas-agentd
systemctl status pjas-agentd
sudo journalctl -u pjas-agentd -f     # op ごとのログはここに出る
```

## 6. Cloudflare Origin CA 証明書と管理画面の vhost

全サイト共通のワイルドカード証明書と秘密鍵を次へ配置する。

```text
/etc/ssl/cloudflare/memo.takusumi.work.pem
/etc/ssl/cloudflare/memo.takusumi.work.key
```

秘密鍵は root のみ読めるようにする。

```bash
sudo chown root:root /etc/ssl/cloudflare/memo.takusumi.work.{pem,key}
sudo chmod 644 /etc/ssl/cloudflare/memo.takusumi.work.pem
sudo chmod 600 /etc/ssl/cloudflare/memo.takusumi.work.key
```

```bash
sudo cp systemd/pjas-ctrl.apache.conf /etc/apache2/sites-available/pjas-ctrl.conf
sudo editor /etc/apache2/sites-available/pjas-ctrl.conf   # ServerName と Require ip を自環境に
sudo a2ensite pjas-ctrl && sudo apachectl configtest && sudo systemctl reload apache2
```

管理画面は公開しないこと。VPN / 社内 NW / mTLS のいずれかで到達を制限する。

php-fpm 側の推奨設定 (アップロードと SSE のため):

```ini
; /etc/php/8.4/fpm/conf.d/99-pjas-ctrl.ini
upload_max_filesize = 512M
post_max_size = 520M
max_execution_time = 0        ; SSE ストリーム用 (fastcgi_read_timeout も伸ばす)
```

## 7. 動作確認

1. `https://ctrl.example.com/` にログイン
2. サイドバーの agent 表示が「稼働中」であること
3. サービスを 1 つ登録 → 「Apache 設定」タブで差分を確認して適用
4. 小さな zip をデプロイ → ジョブ画面にログが流れ、`current` が張り替わること
5. `sudo -u www-data php bin/pjasctl services` が同じ状態を返すこと

## 8. 運用メモ

- **証明書更新**: Cloudflare Origin CA 証明書を差し替えた後に `sudo apachectl graceful` を実行する
- **バックアップ**: `/var/lib/pjas/pjas.db` (状態と監査ログ) と各サービスの `shared/`
- **既存サービスの取り込み**: 先に `releases/<tag>/` へ現物を移し `current` を張ってから
  pjas-ctrl に登録すると、以降のデプロイとロールバックが揃う
- **agent が落ちている場合**: 画面は閲覧できるが、デプロイと Apache 操作はすべて拒否される
- **定期処理**: コード更新後に `sudo -u www-data php8.4 bin/pjas-migrate` でDBを更新する。
  定期処理は `/etc/cron.d/pjas-task-*`、実行内容は `/var/lib/pjas/cron/`、ログは `/var/log/pjas/cron/` に保存される

## 常駐プログラムの管理

管理者権限で「常駐プログラム」を開き、サイトごとに新規作成・設定編集・起動・停止・再起動ができます。登録と操作は「操作の記録」に残ります。サイトの current フォルダが存在することが必要です。

- 新規作成: 表示名と実行内容を指定します。サイトの公開中フォルダで www-data 権限で実行し、異常終了時は再起動します。保存だけでは起動しません。編集した実行内容は次の起動・再起動から有効です。関連付け先のサイトも編集できます。
- 既存プログラム: サーバーの systemd サービス一覧から選んでサイトへ関連付けます。実行設定と稼働状態は変更せず、表示名を編集できます。実行内容の編集はサーバーの元のサービス定義で行ってください。
- 関連付けのないプログラムや別サイト名を指定した操作はエージェント側でも拒否します。1つのプログラムは1つのサイトへ関連付けます。pjas-agentd 自身は関連付け・操作できません。

以前の managed_daemons 一覧は操作許可として使用しません。Apache・PHP・cron も、必要なサイトへの明示的な関連付けが完了するまでは操作できません。

関連付け台帳は `/var/lib/pjas-daemons/registry.json`、新規サービスは `/etc/systemd/system/pjas-daemon-*.service` に保存します。台帳と実行スクリプトは root 所有で、バックアップ対象にしてください。自動起動の有効化は必要に応じてサーバーで `sudo systemctl enable 対象.service` を実行してください。公開バージョンの切り替え後、常駐プログラムを新バージョンへ切り替えるには再起動してください。

既存環境の更新時は、systemd の書き込み許可も更新します。

```bash
sudo install -d -o root -g root -m 755 /var/lib/pjas-daemons
sudo cp /opt/pjas-ctrl/systemd/pjas-agentd.service /etc/systemd/system/pjas-agentd.service
sudo systemctl daemon-reload
sudo systemctl restart pjas-agentd
```

特殊なApache設定（dist配信・API・WebSocket）の例は [pjas-chat の公開設定](pjas-chat-apache.md) を参照してください。
