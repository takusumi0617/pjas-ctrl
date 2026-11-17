# 開発・検証

[目的別ガイド](../README.md) / [構成と権限](security.md)

このページはコードを変更する人向けです。本番サーバーの導入は [新規導入](install.md) を使用してください。

## リポジトリ構成

| 場所 | 内容 |
| --- | --- |
| `public/` | 管理画面の入口 `index.php` と静的ファイル |
| `app/Http/` | ルーティング、認証、CSRF、コントローラー |
| `app/Domain/` | サイト・公開・マニフェスト・予約・監視等のアプリ層 |
| `app/Agent/` | 許可された操作を送るRPCクライアント |
| `app/Support/` | 設定、DB、監査、暗号鍵等 |
| `agent/lib/` | 特権側の入力検証、プロセス・ファイル・台帳処理 |
| `agent/ops/` | サイト・公開・Apache・コンポーネント等のRPC実装 |
| `views/` | PHPテンプレート |
| `bin/` | DB初期化、移行、CLI、初回準備、定期実行、実ホスト検査 |
| `db/schema.sql` | Web用SQLiteスキーマ |
| `systemd/` | サービス・タイマー・Apache/FPM設定例 |
| `tests/` | PHP・Pythonの回帰テスト |
| `examples/` | アプリ別のマニフェスト・Apache・許可台帳の例 |

Composerやnpmによるctrl自身の依存導入は不要です。PHP 8.1以上と `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `pcntl`, `posix`、Python 3、Git、OpenSSL CLI、`tar`、`unzip`、`curl`、`setpriv` を用意します。標準の本番サービス・初回準備の検証にはPHP 8.5とApache・systemdが必要です。

## ローカルで管理画面を起動する

既存の `config.php` がある場合は、その値を確認し、上書きせずに使ってください。開発用のDB・アップロード・ログ・暗号鍵は、本番と別の書き込み可能な場所へ向けます。

1. `config.php.example` を未作成の場合だけ `config.php` へコピーする。
2. `db`, `upload_dir`, `job_dir`, `www_root`, `security_key`, `agent_socket` を開発用の絶対パスへ変更する。
3. ローカルのHTTP接続に限り `force_https` を `false` にする。本番の設定へ持ち込まない。
4. 必要なディレクトリを作り、DBを移行してからローカル管理者を作成する。

```bash
php bin/pjas-migrate
php bin/pjas-init developer@example.com
php -S 127.0.0.1:8080 -t public public/index.php
```

起動後は `/login` にフォームが表示され、未ログインの `/` がログイン画面へ転送されることを確認します。画面・DBの開発はこの状態でもできますが、特権agentを起動していなければ公開・Apache変更・コンポーネント操作等は実行できません。ローカルのログイン画面表示だけで、公開処理の動作確認が完了したとは判断しないでください。

## 回帰テスト

通常の開発ユーザーで、`sudo` を付けずにリポジトリのルートから実行します。各PHPスクリプトは独立したテストです。途中の失敗を見落とさないよう、次の例は最初の失敗で終了します。

```bash
(
  set -e
  for test in tests/*.php; do
    php "$test"
  done
  python3 tests/security_archives.py
  python3 tests/component_files.py
  python3 tests/isolate_tree.py
)
```

主な確認範囲は次のとおりです。

| 領域 | 代表的なテスト |
| --- | --- |
| Web・agent間の許可操作 | `tests/agent_ops_parity.php` |
| 通常公開・切り戻し・取り込み | `tests/manual_rollback.php`、`tests/adopt_plan.php` |
| Apache生成・履歴・部分更新 | `tests/vhost_parity.php`、`tests/apache_history.php`、`tests/error_only_update.php` |
| 権限・ログイン | `tests/access_and_2fa.php`、`tests/security_session_proof.php` |
| v2解析・状態・初期化・保存と復元 | `tests/component_manifest.php`、`tests/components_workflow.php` |
| TLSの証明書検査・実接続・復旧 | `tests/component_tls.php`、`tests/component_tls_apply.php` |
| アーカイブ・ファイル操作 | `tests/security_archives.py`、`tests/component_files.py` |

`tests/vhost_parity.php` は互換動作の設定を `tests/support/legacy_chat.conf` から読み込みます。新規構成の配備手順として使用しません。

## 実ホストで確認すること

回帰テストではApacheやsystemdの外部コマンドを模擬する箇所があります。一部のテストは実際のHTTP/TLS通信や暗号化・復元を行いますが、本番ホストの権限や外部サービスの設定すべてを検証するものではありません。

- [新規導入](install.md) のサービス・CLI・HTTPS・初回公開確認。
- [運用機能](usage.md) のGitHub App、通知先、試験公開等の接続確認。
- [隔離コンポーネント](reference.md) の専用UID間のアクセス拒否、FPMソケットACL、実際のサービス起動、バックアップ復元演習。

v2サイトの設定・起動後、対象サーバーで次を実行します。

```bash
sudo php8.5 /opt/pjas-ctrl/bin/pjas-components-check pjas-cert
```

root以外での実行は終了コード77で未実施を示します。実行ユーザー・保護領域・秘密ファイル・コード権限・ApacheからFPMソケットへの接続を確認します。CAエンジン固有の整合性や、外部署名ワーカーとの二重稼働防止は、対応するアダプターと実環境で確認してください。

通常サイトの実ホスト検証は `sudo php8.5 bin/pjas-sites-check` を実行します。root以外は終了コード77で未実施を示します。単一UIDだけが割り当てられた開発環境では、他UIDによる拒否やApache再起動の検証はできません。

`tests/site_upgrade.php` は実SQLiteでDBの比較・更新を検証し、systemd・隔離・HTTP処理を模擬して移行、中断後の再開、HTTP失敗時の再停止、設定復旧を確認します。実ホストでのsystemdの起動とUIDの検証は別途必要です。

`tests/api_fcgi.py` は実Apacheを非特権で起動し、生成VirtualHostのconfigtestとFastCGI受信値を検証します。`PJAS_TEST_APACHE=/usr/sbin/apache2 python3 tests/api_fcgi.py` で実行します。CLIのPHPがPATH上に必要です。APIの固定SCRIPT_FILENAME、URI/クエリ/Authorization保持、通常PHPと静的UIの独立性を確認し、CIでも実行します。

## 構成と追加実装

Webの認証・画面・DBは`app`と`views`、特権操作は`agent/ops`、root管理状態と検査は`agent/lib`、PHPの実行環境と共通エラーページは`shared`です。Webから新しい操作を追加する場合は`AgentClient::OPS`とagentの登録表を同時に変更し、権限・対象サイト・CSRF・再認証・監査を確認します。

アイコンは同梱のMaterial Symbolsスプライトと`app/Support/Icons.php`を使用します。新しい操作には見えるラベルを付け、状態を色だけで伝えません。フォームのラベル、狭い画面での表示、接続切断時の操作継続も確認します。

ctrl更新のテストはGit操作・GitHub App認証・状態遷移・復旧を、外部サービスとsystemdを置き換えて検証します。実ホストではprivateリポジトリのアクセス許可、更新サービスの権限、PHP停止の影響、HTTP復帰、中断復旧を別途確認します。テストの成功を本番ホスト全体の検証完了とは扱いません。

## 文書を変更する

入口はルートのREADMEと、導入・日常操作・保守・復旧・仕様・セキュリティ・開発の7ガイドです。操作手順は担当ガイドの一か所に書き、他の文書から参照します。日付別の導入・更新ガイドは増やしません。旧情報は現行ツリーから削除し、必要な履歴はGitで参照します。

設定の既定値・CLI・画面名は現行コードと照合し、文書のリンクと記載コマンドの存在を検査します。Schema、構成例、第三者ライセンスは文書削減の対象にしません。
