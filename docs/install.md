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
- 既存プログラム: サーバーの systemd サービス一覧から選んでサイトへ関連付けます。起動設定の取り込みを選ぶと、起動コマンドと作業フォルダをctrlで編集できます。元のサービス定義は保持し、サービス名.service.d/zzzz-pjas-ctrl.conf で必要項目だけを上書きします。環境変数ファイルなどの独自設定は維持します。
- 関連付けのないプログラムや別サイト名を指定した操作はエージェント側でも拒否します。1つのプログラムは1つのサイトへ関連付けます。pjas-agentd 自身は関連付け・操作できません。

以前の managed_daemons 一覧は操作許可として使用しません。Apache・PHP・cron も、必要なサイトへの明示的な関連付けが完了するまでは操作できません。

関連付け台帳は `/var/lib/pjas-daemons/registry.json`、新規サービスは `/etc/systemd/system/pjas-daemon-*.service` に保存します。台帳と実行スクリプトは root 所有で、バックアップ対象にしてください。自動起動の有効化は必要に応じてサーバーで `sudo systemctl enable 対象.service` を実行してください。パス追従を有効にしたプログラムは、公開バージョンの切り替え・ロールバック時に稼働中のものだけ再起動します。停止中のものは起動しません。

既存環境の更新時は、systemd の書き込み許可も更新します。

```bash
sudo install -d -o root -g root -m 755 /var/lib/pjas-daemons
sudo cp /opt/pjas-ctrl/systemd/pjas-agentd.service /etc/systemd/system/pjas-agentd.service
sudo systemctl daemon-reload
sudo systemctl restart pjas-agentd
```

特殊なApache設定（dist配信・API・WebSocket）の例は [pjas-chat の公開設定](pjas-chat-apache.md) を参照してください。

## 移行時に既存の Apache 設定を継承する

通常の生成では表現できない独自設定があるサイトは、「公開設定」→「移行時のみ：既存設定を継承する」で移行元の conf を選び、「継承する内容を確認」を押します。差分を確認して「反映する」を押すと、継承元のコピーを保存し、以後もその設定をベースに生成します。一括登録済みのサイトでも、元の conf が sites-available に残っていれば実施できます。

- 独自のディレクティブ、Directory / Location / IfModule、転送やアクセス制限は保持します。追加欄の許可一覧にない既存設定も継承できます。
- DocumentRoot と対応するサイト内パスを current 配下へ変更し、443 の証明書・秘密鍵は共通の Cloudflare 証明書へ統一します。古い証明書チェーン指定は除去します。shared のパスは維持します。
- HTTPSのない設定でHTTPSを有効にしている場合は、元のHTTP設定を残し、同じ独自設定を使う443の設定を追加します。元からあるHTTP転送ルールは保持します。
- プレビュー後に継承元が変更された場合は再確認が必要です。configtest または反映に失敗すると、設定を戻して継承を確定しません。元の conf 本文は変更せず、同じドメインの古い有効設定を無効化します。
- 継承元は sites-available 内にある root 所有・他ユーザー書き込み不可の conf に限ります。全VirtualHostのServerNameが対象サイトに一致し、80/443を使う通常形式のファイルが候補になります。別サイトと共用するconf、マクロ、外部ファイルに分かれたDocumentRootなどは手動で整理してください。候補に出ない場合、HTTPSの設定・ServerName・所有者・権限も確認してください。
- このモードは元の設定を維持するため、通常の追加設定欄・PHPバージョン・AllowOverrideからの自動生成を使用しません。元のPHPハンドラーやモジュール指定は保持されます。継承した証明書・公開フォルダ以外の設定変更はサーバー管理者が保存済みの継承元を確認して行います。

継承元のコピーは `/etc/apache2/sites-available/.pjas-inherited-サイト名.json` に root のみ読み書き可能な状態で保存します。バックアップ対象に含めてください。元設定の再取り込みは初回の継承時のみ可能です。元ファイルを編集しても保存済みのコピーには自動反映しません。DBの更新は不要です。

## デーモンの実行内容の編集とサイト移行

「常駐プログラム」の「設定を編集する」で、作成済みのデーモンに加えて既存デーモンの起動コマンドと作業フォルダも編集できます。既存デーモンは「起動設定もctrlで管理し、公開先の変更に追従する」を選びます。作業フォルダはサイト内の相対パスです。既存デーモンの起動コマンドは `/usr/bin/php8.4 websocket/server.php start` のように実行ファイルの絶対パスから始める1行で指定します。systemd の ExecStart として実行し、シェルを経由しません。

`{{site_root}}` は `/var/www/サイト名/current` に置き換わります。サイトの直置きパスや日時付き releases のパスはこの記法へ正規化します。shared の固定パスは維持します。保存だけでは再起動せず、再起動ボタンで変更を反映します。以降の公開・ロールバック時には、追従対象の稼働中プログラムを再起動して同じバージョンにそろえます。以前のバージョンで作成したデーモンも一度設定を保存してください。

サイトの「一括登録」と、登録済みサイトの「概要」→「既存ファイルを取り込む」には、移行する既存デーモンの複数選択欄があります。ファイル移動前に起動設定を検証し、current へ切り替えた後に関連付けとパスの上書きを設定します。稼働中なら再起動し、停止中なら起動しません。デーモンの移行選択には管理者権限が必要です。既にファイル移行済みなら「常駐プログラム」で関連付けと起動設定の取り込みを行えます。

pjas-chat の既存 `pjas-websocket.service` は、`User=www-data`、`WorkingDirectory=/var/www/pjas-chat` の構成ならそのまま選択できます。作業フォルダと起動パスは current へ追従し、`EnvironmentFile=/etc/pjas-chat.env` は維持します。元設定の EnvironmentFile・ExecStartPre/Post・ExecStop/Post・PIDFile・ReadWritePaths・ReadOnlyPaths にサイト内の直置きパスがあれば、それらも current へ変更します。環境変数ファイルの中身や外部スクリプト内の固定パスまでは書き換えません。

内容編集・自動パス移行の対象は、サイト内の作業フォルダを使い、www-data で動く simple/exec 型の単一 ExecStart サービスです。root 実行や forking 型などは既存の起動・停止管理のみとし、この編集の対象外です。元設定は上書きせず、ctrl用のdrop-inで変更します。`/var/lib/pjas-daemons` と `/etc/systemd/system/対象.service.d/zzzz-pjas-ctrl.conf` をバックアップしてください。

## 公開GitHubから新しいサイトを登録する

「サイト一覧」→「サイトを登録する」で、公開GitHubリポジトリのURL（または 所有者/リポジトリ）を入力します。ブランチ・タグを省略するとリポジトリの既定ブランチを使用します。GitHubの認証情報は使用せず、privateリポジトリは対象外です。

公開アドレス・公開フォルダ・PHPの種類・動作確認ページなどを指定して登録すると、ファイル取得、準備処理、公開設定の反映、動作確認をジョブとして実行します。取得元は保存されるため、次回以降も通常のGit公開を利用できます。URLが空欄なら従来どおりサイト登録だけを行います。DB更新は不要です。

GitHubリポジトリには様々な構成があります。public・dist・ルート直下など、公開フォルダをリポジトリに合わせて指定してください。npmやComposerが必要なら準備処理を指定し、DB・秘密鍵・環境変数の設定はアプリの手順に従ってください。取得や公開に失敗してもサイト登録と取得元は残るので、設定を修正し通常のGit公開から再試行できます。

取得元台帳はroot所有の `/var/lib/pjas-repositories/サイト名.json` に保存します。既存の `agent/config.php` の `git_repos` も引き続き利用できます。Ubuntu側でGitHubへのHTTPS通信と git コマンドが必要です。更新時は以下を実行します。

```bash
sudo install -d -o root -g root -m 755 /var/lib/pjas-repositories
sudo cp /opt/pjas-ctrl/systemd/pjas-agentd.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl restart pjas-agentd
```

## サイトの一時停止と自由なHTMLページ

サイトの「概要」→「サイトの一時停止・再開」でHTTPコードとHTMLを指定します。404・403・410・503など、200〜599のコードを使用できます。204・205・304はHTTPの仕様上本文を返せません。HEADリクエストも本文なしです。サイトの公開設定を反映済みであることが必要です。

HTMLはデータとしてそのまま表示し、PHPとして実行しません。CSS・JavaScriptはインラインに記載するか外部URLを使ってください。同じサイトの全URLを停止ページに切り替えるため、そのサイトにある画像・CSSのURLも停止ページになります。管理画面ではHTMLをエスケープして編集欄に表示し、管理画面自身で実行しません。

HTTPとHTTPS、別名ドメイン、APIやWebSocketの新規接続も停止ページに切り替えます。HTTP公開の切替であり、バックグラウンドのデーモン・定期処理は停止しません。既に確立済みの接続は直ちには切断しません。停止ページはキャッシュしないヘッダーを返しますが、外部キャッシュに既に残っているページの削除は別途行ってください。

停止状態はリリースから独立して保存し、公開・ロールバックや公開設定の再反映でも維持します。停止中の公開では通常ページのヘルスチェックをスキップします。「サイトを再開する」で、独自設定の継承を含む通常の公開設定へ戻します。反映に失敗した場合は公開設定と停止状態を復元します。

停止ページの応答にはPHP 8.4 FPMを使用します（静的サイトも同様）。`agent/config.php` の `pause_fpm_socket` でソケットを指定できます。PHP-FPMを停止しないでください。保存先 `/var/lib/pjas-pauses` はroot所有とし、バックアップに含めます。DB更新は不要です。

既存環境では以下の更新を行います。

```bash
sudo install -d -o root -g root -m 755 /var/lib/pjas-pauses
sudo cp /opt/pjas-ctrl/systemd/pjas-agentd.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl restart pjas-agentd
sudo systemctl reload php8.4-fpm
```
