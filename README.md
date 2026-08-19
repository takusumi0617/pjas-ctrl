# pjas-ctrl

`/var/www/<サブドメイン>` に置かれる pjas 各サービス (PHP / 静的サイト) の
デプロイ・Apache 設定・運用を一元管理するコントロールプレーン。

設計の詳細は [docs/architecture.md](docs/architecture.md)、
導入手順は [docs/install.md](docs/install.md)。

## 構成

```
public/            管理画面の DocumentRoot (index.php のみ)
app/
  Http/            ルータ・認証・RBAC・CSRF・コントローラ
  Domain/          サービス / リリース / ジョブ / デプロイ / vhost 生成
  Agent/           特権デーモンへの RPC クライアント (許可 op のみ送れる)
  Support/         設定・DB・監査ログ
agent/             pjas-agentd (root)。特権操作の唯一の実行者
  lib/             Spec (入力検証) / Proc (シェルを使わない実行) / Paths / JobLog
  ops/             Service, Deploy, Vhost, Fs, Tls, Health
views/             画面 (PHP テンプレート)
bin/pjas-init      DB 初期化と owner ユーザー作成
bin/pjasctl        CLI (CI から利用)
db/schema.sql      SQLite スキーマ
systemd/           pjas-agentd.service と管理画面用 Apache 設定
tests/             web と agent の vhost 生成一致テスト
```

## 権限の境界

- 管理画面と API は `www-data` で動き、**root 権限を持たない**
- root 操作は `pjas-agentd` に Unix ソケット経由の RPC で依頼する
- 送れるのは登録済み op 名 + パラメータのみ。**任意コマンド文字列も設定ファイル本文も送れない**
- agent は受け取った値を `agent/lib/Spec.php` で再検証する。web 側の検証は UX のためのもので、権限判断はしない
- after_deploy フックと vhost 追加ディレクティブは許可リスト方式
- vhost 適用時、同じ ServerName を持つ管理外の有効サイトは自動で無効化する (失敗時は元に戻す)

## デプロイの流れ

初回デプロイでは、すでに `/var/www/<name>` にあるファイルを 1 つのリリースとして引き継ぎ、
`current` をそこへ向けてから新リリースの処理に入る (既存サイトを消さず、切り戻し先にする)。
新しいファイルをまだ用意していない場合は `pjasctl adopt <service>` (画面では
「既存ファイルを取り込む」) で、引き継ぎだけを単独で実行できる。

`releases/<timestamp>` へ展開 → shared リンク → フック → `php -l` / `apachectl configtest`
→ `current` シンボリックリンクを原子的に張り替え → reload → ヘルスチェック
→ 失敗時は直前リリースへ自動復帰 → 保持数を超えたリリースを削除。

進捗は job ログに書かれ、画面には SSE で流れる (`/jobs/<id>`)。

## よく使う操作

```bash
sudo -u www-data bin/pjasctl services
sudo -u www-data bin/pjasctl deploy billing ./billing-1.8.2.zip --label v1.8.2
sudo -u www-data bin/pjasctl adopt billing            # 既存ファイルをそのまま管理下に置く
sudo -u www-data bin/pjasctl rollback billing 20260814-183012
sudo -u www-data bin/pjasctl vhost billing            # 差分表示
sudo -u www-data bin/pjasctl vhost billing --apply    # 適用
```

## テスト

```bash
php tests/vhost_parity.php
php tests/adopt_plan.php
php tests/vhost_conflicts.php
```

画面で承認した vhost と agent が実際に書き出す vhost が一致することを確認する。
許可ディレクティブ / フック許可リストの回帰テストも兼ねているので、CI で必ず実行する。

## 要件

PHP 8.1 以上 (pdo_sqlite, pcntl, posix)、Apache 2.4 (mod_rewrite, mod_proxy_fcgi, mod_ssl)、
`unzip` / `tar` / `curl` / `setpriv`、任意で `certbot`、`git`。
