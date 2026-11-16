# サイト隔離とPHPアップグレード

[ドキュメント一覧](README.md) / [更新手順](upgrade.md)

新規サイトは専用UID/GIDとサイト別FPMを使用し、PHP 8.5を既定とします。既存フォルダの登録・既存DBの更新では、稼働中の所有者を自動変更しません。取り込み・公開後、サイトの設定画面から明示的に移行します。

## 権限の構成

- Apacheは `pjas-apache`、旧PHPは `www-data`、新規サイトはサイトごとの `pj-…` ユーザーで動きます。Apache内のmod_php・CGI・WSGI・Passengerによるアプリ実行は準備時に拒否します。
- FPMソケットはApacheだけが接続できる0600です。旧共通ユーザーからも接続できません。Apacheは信頼する共通プロセスです。
- サイトの親・公開コードはroot所有、専用グループには読み取りだけを許可します。アプリはsharedだけを書き換えます。セッションと一時ファイルもshared内に分けます。
- ApacheのACLは公開ツリーと、公開ツリーから明示的にリンクされた共有データに限定します。秘密ファイル・DBは公開フォルダ外に置いてください。
- フック・新規常駐処理・cron・バックアップ・ファイル操作も同じサイトユーザーを使用します。専用ユーザーへの補助グループ付与は禁止です。
- 一般サイトの隔離はOSのUID/GID・ファイル権限の分離です。コンテナによるネットワーク隔離やroot侵害への防御ではありません。さらに厳しい権限分割・CA状態管理にはv2を使用します。

## サーバーの準備

[更新手順](upgrade.md)に沿って設定・DB・サービス定義を退避し、作業時間を確保します。PHP 8.5 CLI/FPMと必要な拡張、aclを、組織が認めた提供元からインストールしてください。

```bash
sudo apt install acl php8.5-cli php8.5-fpm php8.5-sqlite3 php8.5-mbstring
sudo php8.5 bin/pjas-install-features --check --php=8.5
sudo php8.5 bin/pjas-install-features --apply --php=8.5
```

`--apply` はApacheの実行ユーザー変更と再起動を伴い、全サイトへ短時間影響します。旧PHPのサイト用FPMは残します。ctrl専用の旧FPMプールだけを退避して無効化し、同じ管理用ソケットを複数バージョンが使用することを防ぎます。独自のApache実行ユーザー設定やApache内のアプリ実行モジュールがある場合は、検査結果を確認して先に構成を整理してください。

既存v2サイトは管理者台帳のPHPプロファイルの `apache_user` も `pjas-apache` へ変更し、作業時間内にコンポーネント画面から再プレビュー・適用してFPMのACLを更新します。CA初期化・状態復元は行いません。v2のPHP変更は台帳のsite.runtime・fpm_binary・workerの許可実行ファイルと固定マニフェストを照合して、既存のレビュー手順で適用します。

## 既存サイトの移行

常駐プログラム・cronがあるサイトは、[CLI移行スクリプト](site-upgrade-cli.md)を使用してください。関連付け解除は不要です。従来のUIには解除操作がないため、画面上で停止・解除・再登録を組み合わせる手順は使用しません。

```bash
cd /opt/pjas-ctrl
PJAS_UPGRADE_ID="$(sudo php8.5 bin/pjas-site-upgrade plan 対象サイト名 --php=8.5 --id-only)"
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"
sudo php8.5 bin/pjas-site-upgrade apply "$PJAS_UPGRADE_ID" \
  --accept-standard-apache --external-processes-stopped
sudo php8.5 bin/pjas-site-upgrade activate "$PJAS_UPGRADE_ID" --confirm-activation
```

`apply`は常駐処理とcronを退避・停止し、サイトを一時停止してから隔離を適用します。元のunit名・関連付け・環境設定・メモリ制限を維持し、専用ユーザーと選択PHPへの変更をdrop-inに追加します。完了後も停止状態を維持し、`activate`で元々稼働していた処理だけを再開します。起動・HTTP検査に失敗した場合は再停止します。

外部の定期実行・ワーカーは事前に停止してください。実行中のcron等が検出された場合は、その終了を待ち、同じIDで`apply`を再実行します。隔離処理そのものが中断した場合は、同じ処理を繰り返さずCLIの`rollback`を使用します。

独自Apache設定は退避して標準設定へ切り替えます。APIプロキシ・別名ドメイン・.htaccess依存のサイトは、先に対応構成を準備してください。共有FPMの再起動は同じバージョンを使う他サイトにも影響します。

常駐処理・cronがないサイトは、引き続き設定画面の「隔離とPHPアップグレード」から移行できます。

```bash
sudo php8.5 bin/pjas-sites-check
sudo php8.5 bin/pjas-components-check pjas-cert  # v2利用時
```

未移行サイトがあれば検証は終了コード1、root以外は77です。PHPの構文検査・HTTP検査に加えて、アプリのDB読み書き・アップロード等も確認してください。

## PHP 8.6と以降の追加

PHPの許可バージョン・パス・フックは `shared/PhpRuntime.php` に集約しています。8.6は準備用の選択肢として登録済みです。CLI/FPMが未導入の選択肢は無効表示し、適用時も導入を必ず確認します。8.6の実行互換性を検証済みという意味ではありません。

対象PHPと拡張を導入後、まず検証環境で試し、同じ移行画面からサイト別に切り替えます。ctrl自体は `--php=8.6` で同じインストーラ・systemdテンプレートを使います。管理用の選択値はroot管理の `/etc/pjas-control/php-version` に保存します。通常の再適用でも選択値を維持します。

全サイト・ctrl・試験公開・停止ページ・常駐処理・cron・外部ツールの切り替えを確認するまで、旧PHPパッケージは削除しません。既存のv1共通プールは他サイトが利用している可能性があるため、自動削除しません。

専用ユーザーの作成だけは固定の `pjas-site-identity@.service` が行います。agentの `/etc` 全体を書き込み可能にはせず、FPM設定用の `/etc/php` だけを書き込み許可へ追加します。
