# 既存サイトを pjas-ctrl の管理下に取り込む

`/var/www/<name>` に既に稼働中のサイトがある状態で、それを pjas-ctrl に登録する場合の手順。

**初回デプロイは、そこにあるファイルを自動的に引き継ぐ。** 手で退避してから登録する必要はない
（退避しても構わないが、その場合は切り戻し先が作られない）。それでも Apache 設定の
切り替えだけは手順が必要なので、下の順序を守ること。

## 2 通りの入り口

| やりたいこと | 使うもの |
|---|---|
| いまのファイルをそのまま管理下に置きたい（新しいアーカイブは無い） | `pjasctl adopt <service>` / 画面の「既存ファイルを取り込む」 |
| 移行と同時に新しいバージョンを公開したい | 通常の `pjasctl deploy`（引き継ぎは自動で先に走る） |

どちらも下の引き継ぎ処理を通る。`adopt` は取り込みと `current` の作成だけを行い、
アーカイブの展開・フック・ヘルスチェックによる合否判定は行わない
（公開設定を反映する前でも実行できるようにするため）。すでに管理下のリリースがある場合は拒否される。

## 引き継ぎで起きること

`prev`（管理下の `current`）が無い状態のデプロイでは、アーカイブを展開する前に次が走る
(`agent/ops/Deploy.php` の `adoptExisting()`)。

1. `releases/` と `shared/` を除いた既存の中身を、`releases/<今回のtagより1秒古いtag>` へ移動する
2. 中身が単一のディレクトリだけなら、その中身をリリース直下に上げる
   （`/var/www/billing/billing-app/...` のような一段深い構成を救うため。アーカイブ展開と同じ規則）
3. 所有者と権限を `www-data` / dir 755・file 644 に正規化する
4. `current` をその引き継ぎリリースへ張る
5. 以降は通常のデプロイ（展開 → shared → フック → 検証 → 切替 → ヘルスチェック）

この結果:

- 既存ファイルは**削除されない**。リリース履歴に「引き継ぎ (既存ファイル)」として載る
- **初回デプロイでも自動ロールバックが効く**。新バージョンのヘルスチェックが失敗すれば、
  引き継いだ既存サイトへ戻る
- 切り替え前に失敗しても `current` は引き継ぎリリースを指したままなので、
  管理下のパス (`current/<docroot>`) で既存サイトが配信され続ける
- 引き継いだリリースは、その回の `keep_releases` による削除対象から除外される

### 例外: `current` が外を指すシンボリックリンク

`/var/www/billing/current` がサービスディレクトリの外（例 `/srv/app/releases/x`）を指している場合、
agent は何が公開されているのか判断できないため、**エラーで停止する**。
手動で退避してから再実行すること。

### `current` が実ディレクトリの場合

引き継ぎリリースの中に `current/` という名前のディレクトリとして入る。
そのままだと `docroot` の指定とずれることがあるので、リリース履歴で中身を確認すること。

## それでも順序が要る部分: Apache 設定

vhost の DocumentRoot は常に `/var/www/<name>/current/<docroot>`。**初回デプロイ前に vhost を
反映すると、まだ `current` が無いので 403/404 になる。** 必ずデプロイを先にする。

既存サイトの Apache 設定ファイル（例 `billing.conf`）は、**反映時に自動で無効化される**。
同じ `ServerName` / `ServerAlias` を持つ管理外の有効サイトを探して `a2dissite` する仕組みで、
configtest や graceful に失敗した場合は無効化したサイトも元に戻る。
何を無効化するかは適用前に画面と `pjasctl vhost <service>` の出力で確認できる。

pjas-ctrl が書いた設定 (`# managed by pjas-ctrl` で始まるもの) は対象外なので、
別サービスの設定が巻き込まれることはない。

## 手順

```bash
# 1. 管理画面または CLI からサイトを登録する
#    → /var/www/billing に releases/ と shared/ が作られる (既存ファイルはそのまま)

# 2. 引き継ぐ設定・データを shared へ置く
sudo cp /var/www/billing/.env /var/www/billing/shared/.env
sudo chown -R www-data:www-data /var/www/billing/shared

# 3a. いまのファイルをそのまま管理下に置く場合
sudo -u www-data php bin/pjasctl adopt billing

# 3b. 移行と同時に新しいバージョンを公開する場合 (引き継ぎは自動で先に走る)
sudo -u www-data php bin/pjasctl deploy billing /tmp/billing-1.8.2.zip --label v1.8.2

# 4. 公開設定を反映する (同じアドレスの古い設定は自動で無効化される)
sudo -u www-data php bin/pjasctl vhost billing            # 差分と、無効化される設定の確認
sudo -u www-data php bin/pjasctl vhost billing --apply
```

3b でヘルスチェックに失敗した場合は、引き継いだリリースへ自動で戻っているので、
原因を直して再デプロイすればよい。4 では配信元が旧設定から pjas-ctrl の設定へ切り替わるため、
短い断が入る可能性がある。時間帯は選ぶこと。

## 補足

- サイト名は `^[a-z0-9][a-z0-9-]{0,31}$` のみ。既存ディレクトリが `example.com` や `MyApp` の
  ような名前だと登録できない（先にディレクトリ名を変える必要がある）
- サイト登録時、既存の base ディレクトリの所有者が `www-data:www-data`、権限が 0755 に変わる
- vhost を反映しないまま放置すると、「管理画面には正常と出るが、実際は管理下の設定で
  配信されていない」状態になる。取り込むなら 4 まで通すこと
- 自動で無効化されるのは、同じ `ServerName` / `ServerAlias` を持つ管理外の**有効な**サイトだけ。
  `sites-available` に置いてあるだけの設定や、別ドメインの設定はそのまま残る
