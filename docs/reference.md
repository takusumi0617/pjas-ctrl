# リファレンス

[目的別ガイド](../README.md) / [操作](usage.md) / [保守](maintenance.md)

## 保存先と設定

| 対象 | 標準の場所 |
| --- | --- |
| コード・Web設定 | `/opt/pjas-ctrl`、`config.php` |
| agent設定 | `/opt/pjas-ctrl/agent/config.php` |
| 管理DB・ログイン保護用の鍵 | `/var/lib/pjas/pjas.db`、`/var/lib/pjas/security.key` |
| root管理状態・GitHub App・通知・バックアップ鍵 | `/var/lib/pjas-control` |
| ctrl更新元・管理用PHP | `/etc/pjas-control/update.json`、`/etc/pjas-control/php-version` |
| 更新退避・独立した更新基盤 | `/var/lib/pjas-control/control-updates`、`/var/lib/pjas-control/updater` |
| サイトのコード・共有データ | `/var/www/サイト名/releases`、`current`、`shared` |
| Git取得元・常駐処理・公開停止の台帳 | `/var/lib/pjas-repositories`、`/var/lib/pjas-daemons`、`/var/lib/pjas-pauses` |
| ジョブ・管理用HTTPのログ | `/var/log/pjas/jobs`、`/var/log/pjas/control-web-error.log` |
| 管理者のコンポーネント許可台帳 | `/etc/pjas/components.json` |

Webとagentで、サイト配置先、アップロード先、ジョブログ、RPCソケット、共通TLS証明書・鍵を一致させます。Webの`db`と`security_key`は管理用です。agentの`web_user`・`web_group`は未移行サイト用の互換設定で、管理画面ユーザーには変更しません。

独自の保存先はsystemdの`ReadWritePaths`にも反映します。詳細な既定値は[Web設定例](../config.php.example)と[agent設定](../agent/config.php)を参照します。`config.php`はGit管理外、`agent/config.php`はGit管理対象です。

## サイトの設定項目

| 項目 | 意味 |
| --- | --- |
| 名前 | 小文字英数字・ハイフン、32文字以内。内部のパス・台帳に使用 |
| 公開アドレス | ホスト名。スキームやパスを含めない |
| 種類 | `static`または導入済みの許可PHP。既定は`php8.5-fpm` |
| 公開フォルダ | リリース内の相対パス。通常`public`、ビルド済み画面は`dist`など |
| 動作確認 | パスと期待HTTP番号。HTTP成功だけでアプリ全機能の成功は保証しない |
| 共有パス | リリースをまたいで維持する設定・アップロード等の相対パス |
| DB自動引き継ぎ | `@databases`。初回移行時は書き込み停止が必要 |
| 保持数 | コードの過去リリース数。データバックアップの保持とは別 |
| 公開後処理 | 許可されたコマンドのみ。実行ユーザーはサイトの専用ユーザー |

コードの領域は読み取り専用、書き込み先は`shared`またはコンポーネントの割当領域です。専用環境の`.htaccess`・追加Apache設定は無効です。既存の独自設定を標準化する際はAPI・WebSocket・別名ドメイン・アクセス制限を確認します。

## .pjas v1

リポジトリのルートにJSONの`.pjas`を置きます。読み込みで固定したコミットを確認してから登録・公開します。読み込むだけでは起動・登録しません。

```json
{
  "version": 1,
  "site": {
    "name": "example-site",
    "domain": "app.example.com",
    "runtime": "php8.5-fpm",
    "document_root": "public",
    "https": true,
    "health_path": "/healthz",
    "health_status": 200
  },
  "shared": [".env", "storage"],
  "prepare": [],
  "daemons": [],
  "cron": []
}
```

| キー | 内容 |
| --- | --- |
| `site` | サイト設定。通常項目は確認画面の入力値を優先 |
| `shared` | 永続化する相対パスの配列 |
| `prepare` | 許可一覧にある準備コマンドの配列 |
| `daemons` | `name`、`label`、`command`、`working_directory`、`start_on_install`、`start_on_boot` |
| `cron` | `name`、`label`、`schedule`、`command`、`enabled` |
| `apache` | 既存構成の互換取り込み用。`file`と`source_root`。専用環境へ無条件には適用しない |

常駐処理の初回・OS起動時の起動は明示指定時だけ設定します。cronの`enabled`省略時はtrueです。`{{site_root}}`は公開中の`current`へ置き換えます。サイト内の相対パスを使用し、固定したリリース日時へ依存させません。

## .pjas v2と管理者台帳

v2は複数の処理を、個別のユーザー・永続領域・秘密情報で管理します。v1も専用ユーザーを使用しますが、v2は処理ごとの境界と依存関係を宣言できます。[JSON Schema](pjas-v2.schema.json)と[構成例](../examples/pjas-cert/.pjas)、[許可台帳例](../examples/pjas-cert/components.json)を参照します。

管理者は次を事前準備します。

1. 処理ごとの非rootユーザーと同名の専用プライマリグループ。補助グループ・UID/GIDの共有は不可。
2. 実行ファイル、FPM、アプリのエンジンと検査アダプター。ctrlはCAエンジンや鍵を提供しない。
3. 専用の永続領域・秘密情報の元ファイル。リンクや他処理の領域を参照させない。
4. 許可台帳をroot所有0600で配置し、正規化した取得元リポジトリと対象サイトを一致させる。

| マニフェスト | 管理者台帳で許可するもの |
| --- | --- |
| `requirements` | 導入済み要件・検査方法 |
| `components[].execution_profile` | サイト、処理種別、ユーザー、実行ファイル、FPM、実行制限 |
| `storage` | 対象サイト、使用プロファイル、絶対パス、用途 |
| `secrets` | 対象サイト、使用プロファイル、元ファイル、用途 |
| `setup` | 初回セットアップ、完了判定、未初期化判定 |
| `health_checks` | 処理ごとの検査コマンド・制限時間 |
| `backup_policy` | 保存対象、鍵、整合性保存・復元の検査 |

マニフェストは台帳のIDを参照し、Webから新しいroot権限や任意の絶対パスを許可できません。依存関係は循環を拒否します。処理はコード・他処理の永続領域・秘密情報の元ファイルへ書き込めません。秘密情報は実行用の専用領域へ展開し、更新後に再起動が必要かを表示します。

状態・レビューはroot管理、管理DBの`component_sites`は表示・監視用キャッシュです。Webキャッシュを権限判断には使用しません。root台帳や実効unitを手動編集した場合は、変更を照合して再レビューしてください。

TLS更新は許可済みの証明書プロバイダーと秘密ファイルを使い、期限・名前・鍵・チェーン、Apacheの構文と接続を検査して適用します。Cloudflare Origin CAはオリジン向けで、ブラウザーからの直接接続用証明書とは区別します。

## CLI

通常の管理CLIは`pjas-control`として、隔離・移行・初回登録はrootで実行します。コマンドの実行場所はコードのルートです。

```bash
cd /opt/pjas-ctrl
sudo -u pjas-control php8.5 bin/pjasctl services
sudo -u pjas-control php8.5 bin/pjasctl status example-site
sudo -u pjas-control php8.5 bin/pjasctl deploy example-site /読み取り可能なパス/release.zip --label v1.0
sudo -u pjas-control php8.5 bin/pjasctl logs 123
```

| コマンド | 用途 |
| --- | --- |
| `pjasctl adopt SITE` | 既存の配置を管理リリースへ取り込む |
| `pjasctl rollback SITE TAG` | コードだけを切り戻す |
| `pjasctl vhost SITE [--apply]` | 初回公開設定の作成・既存設定のエラーページ更新。登録変更全体は画面の差分から反映 |
| `pjasctl components OP SITE --input FILE` | v2操作へJSONパラメータを渡す |
| `pjas-site-upgrade help` | 単独・一括移行、診断、ルーティングの操作一覧 |
| `pjas-control-update install/status/recover` | ctrl更新の初回登録・状態・中断復旧 |
| `pjas-sites-check` | 通常サイトの実ホストでの隔離検査 |
| `pjas-components-check SITE` | 処理別の実UID・領域・秘密情報・ソケットの検査 |
| `pjas-migrate` | 管理DBへの追加スキーマ適用。データ復旧とは別 |

v2の`OP`は`status`、`plan`、`apply`、`release`、`publish`、`action`、`setup`、`rollback`、`backup`、`restoreplan`、`restore`、`resume`、`webplan`、`webapply`、`tls.plan`、`tls.apply`、`tls.status`です。確認で返されたレビューIDを同じ操作の適用に使います。入力JSONは秘密情報として扱い、CLIユーザー以外に公開しません。
