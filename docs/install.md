# 新規導入

[ドキュメント一覧](README.md) / [既存環境の更新](upgrade.md)

まだpjas-ctrlを導入していないサーバー向けの手順です。完了すると、専用ユーザーで動く管理画面、特権agent、自動監視タイマーが利用できるようになります。管理対象サイトの登録は、その後に行います。

## 進め方と前提条件

標準構成は **Ubuntu 24.04、PHP 8.5、Apache 2.4、systemd** です。以下のコマンドはサーバー上で実行します。`sudo` を使える管理者アカウントが必要です。

| 先に決めるもの | この手順での例・注意点 |
| --- | --- |
| コードの配置先 | `/opt/pjas-ctrl`。別の場所ではサービス定義のパスも確認 |
| 管理画面のURL | `https://ctrl.example.com`。DNSを用意し、ApacheのServerNameに設定 |
| 管理者メールアドレス | `ops@example.com`。ログインIDとして使用 |
| 管理画面にアクセスできる範囲 | VPN・社内ネットワーク・IP制限など。実際の接続元を許可 |
| HTTPS証明書と秘密鍵 | 標準の例はCloudflare Origin CA。対象ドメインを含むものを別途発行 |
| 管理対象サイトの配置先 | `/var/www`。Web側とagent側の設定を揃える |

Cloudflare Origin CA証明書は、Cloudflare経由の接続を想定しています。ブラウザーからオリジンサーバーへ直接接続しても通常は信頼されません。直接アクセスする構成では、その接続で信頼できる証明書と鍵を用意し、以下の設定を合わせてください。

**実行順序:** パッケージ → コード配置 → 設定 → 管理者作成 → 証明書・Apache設定 → 管理画面の分離 → 公開と確認。初期状態の管理画面は、分離処理が終わるまで有効化しません。

## 1. 必要パッケージを入れる

Ubuntu 24.04の標準PHPは8.5ではないため、この例では第三者のPHPパッケージ提供元 `ppa:ondrej/php` を追加します。利用組織で認めた提供元を使ってください。Debianなど別の環境にPPAの追加コマンドをそのまま実行しないでください。

```bash
sudo apt update
sudo apt install -y software-properties-common ca-certificates
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y apache2 php8.5-fpm php8.5-cli php8.5-sqlite3 php8.5-mbstring unzip curl util-linux cron git python3 acl
sudo a2enmod rewrite proxy_fcgi setenvif ssl headers
sudo a2enconf php8.5-fpm
sudo systemctl enable --now cron php8.5-fpm
```

PHP拡張と、Pythonの標準ライブラリを確認します。`bin/pjas-install-features` はPHP 8.5の実行ファイル・FPMを前提にしています。

```bash
php8.5 -v
php8.5 -r 'foreach (["pdo_sqlite", "sqlite3", "mbstring", "openssl", "pcntl", "posix"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "不足: $ext\n"); exit(1); } } echo "PHP拡張: OK\n";'
python3 -c 'import sqlite3, tarfile, zipfile; print("Python標準ライブラリ: OK")'
```

## 2. コードと作業ディレクトリを配置する

```bash
sudo git clone https://github.com/takusumi0617/pjas-ctrl.git /opt/pjas-ctrl
sudo chown -R root:root /opt/pjas-ctrl
sudo chmod -R go-w /opt/pjas-ctrl
sudo groupadd -f pjas
sudo install -d -o www-data -g pjas -m 0770 /var/lib/pjas /var/lib/pjas/uploads
sudo install -d -o root -g pjas -m 0750 /var/log/pjas /var/log/pjas/jobs
```

この時点の `www-data` 所有は、初回DB作成までの準備用です。手順6で管理DB・アップロード先を `pjas-control` へ移し、本番サイトのユーザーを管理用RPCグループから外します。`www-data` を `pjas` グループへ追加する必要はありません。

## 3. 設定を作成する

```bash
sudo cp /opt/pjas-ctrl/config.php.example /opt/pjas-ctrl/config.php
sudo chown root:www-data /opt/pjas-ctrl/config.php
sudo chmod 640 /opt/pjas-ctrl/config.php
sudo editor /opt/pjas-ctrl/config.php
sudo editor /opt/pjas-ctrl/agent/config.php
```

両ファイルの次の項目を合わせます。既定のパスを使う場合は、値を変更する必要はありません。

| 用途 | Web側 `config.php` | agent側 `agent/config.php` |
| --- | --- | --- |
| サイト配置先 | `www_root` | `www_root` |
| アップロード先 | `upload_dir` | `upload_dir` |
| ジョブログ | `job_dir` | `job_dir` |
| RPCソケット | `agent_socket` | `socket` |
| 共通TLS証明書・鍵 | `tls_cert_file` / `tls_key_file` | `tls_cert_file` / `tls_key_file` |

Web側の `db` は管理用SQLite DB、`security_key` は二段階認証等の暗号鍵です。`force_https` は本番では `true` にします。agent側の `web_user` / `web_group` は通常サイトの実行ユーザーであり、管理画面用の `pjas-control` へ変更する項目ではありません。

標準外の保存先を使う場合は、systemdサービスの `ReadWritePaths` も合わせてください。コード・設定はroot所有とし、アプリの実行ユーザーから書き換えられない状態を維持します。

## 4. 管理DBと最初の管理者を作成する

```bash
cd /opt/pjas-ctrl
sudo -u www-data php8.5 bin/pjas-init ops@example.com
```

12文字以上のパスワードを対話入力します。パスワードはコマンド引数へ書かないでください。手順6の分離処理後、CLIを実行するユーザーは `pjas-control` になります。

## 5. HTTPS証明書と管理画面のApache設定を用意する

標準設定で参照するファイルは次の2つです。リポジトリには証明書も秘密鍵も含まれません。別途用意してから進めてください。

```text
/etc/ssl/cloudflare/memo.takusumi.work.pem
/etc/ssl/cloudflare/memo.takusumi.work.key
```

この名前はリポジトリの既定値です。別のパスを使う場合は、手順3の両設定と、これから配置するApache設定内の証明書・鍵のパスをすべて変更します。

```bash
sudo chown root:root /etc/ssl/cloudflare/memo.takusumi.work.{pem,key}
sudo chmod 644 /etc/ssl/cloudflare/memo.takusumi.work.pem
sudo chmod 600 /etc/ssl/cloudflare/memo.takusumi.work.key
sudo cp /opt/pjas-ctrl/systemd/pjas-ctrl.apache.conf /etc/apache2/sites-available/pjas-ctrl.conf
sudo editor /etc/apache2/sites-available/pjas-ctrl.conf
```

編集する箇所は `ServerName`、`Require ip`、証明書・鍵のパスです。例の `ctrl.pjas.example` とプライベートIP範囲を、そのまま利用しないでください。プロキシやCloudflareを経由する場合、Apacheが認識する接続元IPとアクセス制限の関係も確認します。

この時点では `a2ensite` を実行しません。初回準備コマンドがこのファイルを読み、既存のHTTPS・アクセス元制限を保ちながら管理画面専用HTTPサービスへの転送に変更します。

## 6. 管理画面を専用ユーザーへ分離する

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-install-features --check
sudo php8.5 bin/pjas-install-features --apply
```

`--check` は前提条件と変更予定の確認です。`--apply` は実際にユーザー・DB・サービス・設定を変更します。Apache設定の名前を変更した場合は、両方に `--site=設定名` を追加してください。`.conf` は付けません。

処理後は次の構成になります。

| 役割 | ユーザー・サービス |
| --- | --- |
| 管理画面のPHP | `pjas-control`、FPMソケット `/run/php/pjas-control.sock` |
| 管理画面専用HTTP | `pjas-control-web.service`、待受 `127.0.0.1:8184` |
| 特権操作 | `pjas-agentd.service`（root） |
| 定期監視・予約処理 | `pjas-monitor.timer` |
| 通常の本番サイト | agentの `web_user`（通常 `www-data`） |

設定と既存DBの退避先は `/var/lib/pjas-control/setup-*` です。処理は管理画面の転送設定に加え、既存サイトのApache設定にも機密ファイルの公開防止設定を追加することがあります。既存サイトがある場合は、先に [更新時の影響と退避](upgrade.md) を確認してください。

## 7. 管理画面を有効にし、動作を確認する

```bash
sudo a2ensite pjas-ctrl
sudo apachectl configtest
sudo systemctl reload apache2
systemctl status pjas-agentd pjas-control-web php8.5-fpm pjas-monitor.timer --no-pager
sudo -u pjas-control php8.5 /opt/pjas-ctrl/bin/pjasctl services
```

設定検査が失敗した場合は、その原因を直すまで次のreloadへ進まないでください。新規導入直後、CLIのサイト一覧が空なのは正常です。

1. 許可した接続元から管理画面のHTTPS URLを開きます。
2. 手順4で作成した管理者でログインします。
3. agentが稼働中であることを確認します。
4. 「運用状況」に最終確認時刻が表示されることを確認します。
5. [サイトの登録と日常運用](sites.md) に沿ってテスト用サイトを1つ公開し、実際のHTTP応答と作業履歴を確認します。

起動しない場合のログ確認は [更新・障害時の確認](upgrade.md#問題が起きた場合) を参照してください。

## 次に設定するもの

- [GitHub App・通知・バックアップ・試験公開](operations.md)
- [サーバー画面とDiscord通知](server-monitoring.md)
- [.pjas v1による通常サイトの一括登録](pjas-manifest.md)
- [専用ユーザーとCA保護領域を使うv2サイト](pjas-cert.md)

通常サイトの登録と、pjas-certのCA初期化は別の作業です。v2のエンジン・保護領域・秘密情報・復元検査は、管理者による追加準備が必要です。
