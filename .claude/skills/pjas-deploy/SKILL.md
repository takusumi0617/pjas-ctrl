---
name: pjas-deploy
description: pjas-ctrl が管理するサイト (/var/www/<name>) へのデプロイ・ロールバック・Apache 設定反映・状態確認を、bin/pjasctl 経由で安全に実行する手順。「billing をデプロイして」「本番に上げて」「さっきのリリースに戻して」「vhost を反映して」「サイトが 503 になっている」など、pjas / pjasctl / pjas-agentd / リリース / ロールバック / デプロイ / vhost / 公開 に関わる依頼が出たら必ずこのスキルを参照すること。デプロイ前の確認事項と失敗時の切り分け手順が含まれるので、単純に見えるデプロイ依頼でも先に読むこと。
---

# pjas-ctrl へのデプロイ

pjas-ctrl は `/var/www/<サービス名>` に置かれた各サイトを一元管理するコントロールプレーン。
デプロイは必ず `bin/pjasctl` (または Web UI) を通す。**`/var/www` 配下を直接 `cp` / `rm` / `ln -s`
で書き換えてはいけない** — 権限も監査ログも壊れ、ロールバックが効かなくなるため。

特権操作を実際に行うのは root で動く `pjas-agentd` だけで、`pjasctl` は www-data として
「登録済みの操作名 + パラメータ」を送るクライアントにすぎない。この境界が安全性の根拠なので、
迂回する提案（agent を止めて手で置く、sudo で直接コピーする等）はしないこと。

## 前提

- 作業ディレクトリは pjas-ctrl のインストール先 (例 `/opt/pjas-ctrl`)
- コマンドは **www-data として** 実行する: `sudo -u www-data php bin/pjasctl ...`
  (root で実行すると作られるファイルの所有者がずれる)
- `pjas-agentd` が動いていること

## 標準の流れ

デプロイ依頼を受けたら、次の順で進める。省略していいのは 5 だけ。

### 1. 対象と現状を確認する

```bash
sudo -u www-data php bin/pjasctl services          # 一覧: 名前 / ドメイン / 実行環境 / 現行リリース / 状態
sudo -u www-data php bin/pjasctl status <service>  # いまのヘルスチェック結果 (正常なら終了コード 0)
```

デプロイ前の現行リリース tag は必ず控えておく。切り戻し先になる。
`status` が既に NG のときは、そのままデプロイせず先に理由を確認する
（自動ロールバックの判定が「元から壊れていた」のか「今回壊した」のか区別できなくなるため）。

### 2. アーカイブを用意する

`.zip` / `.tar.gz` / `.tgz` のみ。**アーカイブの中身が公開ディレクトリの直下**になるようにする
（トップに余分な一段があると `docroot` がずれる）。

```bash
cd <アプリのソース>
zip -r /tmp/<service>-<version>.zip . -x '.git/*' 'node_modules/*' '.env' 'storage/*'
```

`.env` や `storage/` のような「リリースをまたいで残すもの」はアーカイブに含めない。
これらはサービス設定の `shared_paths` として引き継がれる（含めると shared リンクと衝突する）。

### 3. デプロイする

```bash
sudo -u www-data php bin/pjasctl deploy <service> /tmp/<service>-<version>.zip --label v1.8.2
```

- `--label` は履歴表示用の名前。バージョン番号など後から分かるものを付ける
- `--no-rollback` は自動切り戻しを**無効化**するフラグ。既定 (付けない) が安全側なので、
  ユーザーが明示的に求めない限り付けない
- コマンドはジョブログを流しながら完了まで待ち、成功なら終了コード 0 / 失敗なら 1 を返す

内部では 8 段階（安全チェック → `releases/<timestamp>` へ展開 → shared リンク → after_deploy
フック → `php -l` と `apachectl configtest` → `current` の張り替え → ヘルスチェック → 古い
リリースの削除）が走る。詳細は `references/pipeline.md`。

### 4. 結果を確認する

```bash
sudo -u www-data php bin/pjasctl status <service>
sudo -u www-data php bin/pjasctl services | grep <service>
```

終了コードとヘルスチェックの両方を見る。ユーザーへの報告には、公開されたリリース tag と
ヘルスチェック結果をそのまま書くこと（「デプロイしました」だけでは検証されたか分からない）。

### 5. Apache 設定を変えたときだけ: vhost を反映する

ドメイン・docroot・実行環境・TLS・追加ディレクティブを変更した場合のみ必要。
通常のコード更新では不要。

```bash
sudo -u www-data php bin/pjasctl vhost <service>            # 差分表示 (適用しない)
sudo -u www-data php bin/pjasctl vhost <service> --apply    # 適用
```

差分を必ず先に見せて、内容を説明してから `--apply` する。

## 切り戻し

```bash
sudo -u www-data php bin/pjasctl rollback <service> <tag>   # tag は 20260819-101204 形式
```

`current` シンボリックリンクを戻すだけなので速く、失敗しにくい。障害時は原因調査より
先に切り戻す判断で構わない（調査対象のリリースはディスクに残る）。

## 失敗したときの切り分け

`references/troubleshooting.md` に症状別の対処をまとめてある。よくある順に:

| 症状 | まず見るところ |
|---|---|
| `エラー: ...` で即失敗 | 引数・アーカイブのパス・拡張子 |
| ヘルスチェック失敗で自動復帰した | アプリのログ、`shared_paths` の引き継ぎ漏れ、`.env` の不足 |
| フックで止まった | 許可リストに無いコマンドを設定していないか (`references/allowlists.md`) |
| `configtest` で止まった | 追加ディレクティブの記述ミス。**この場合 Apache は元の設定のまま止まっているので本番は無事** |
| agent に繋がらない | `systemctl status pjas-agentd`、ソケットの権限、www-data が `pjas` グループに入っているか |

ジョブログは後から追える:

```bash
sudo -u www-data php bin/pjasctl logs <job-id>
```

## やってはいけないこと

- `/var/www/<service>/current` を手で張り替える（監査ログも状態 DB も更新されない）
- `releases/` 配下のファイルを直接編集する（次のデプロイで消える。設定は `shared` へ）
- 許可リストを広げて任意コマンドをフックに通す（権限分離の前提が崩れる）
- 失敗を握りつぶして「成功しました」と報告する

## 既存サイトが `/var/www/<name>` に既にある場合

初回デプロイは、既にそこにあるファイルを「引き継ぎ」リリースとして自動で取り込み、
`current` をそこへ張ってから新リリースの処理に入る。手で退避する必要はなく、
初回でも自動ロールバック先ができる。

ただし vhost の DocumentRoot は `current/<docroot>` を指すため、**初回デプロイより先に
vhost を反映すると 403/404 になる**。既存の Apache 設定 (`billing.conf` など) も
有効なまま残るので `a2dissite` が要る。この状況に当たったら
`references/migration.md` の手順どおりに進めること。

## 参照

- `references/migration.md` — 稼働中サイトの取り込み手順と、そのとき起きること
- `references/pipeline.md` — デプロイ 8 段階の詳細と、どこで失敗すると何が起きるか
- `references/troubleshooting.md` — 症状別の切り分け手順
- `references/allowlists.md` — after_deploy フックと vhost 追加ディレクティブの許可リスト
