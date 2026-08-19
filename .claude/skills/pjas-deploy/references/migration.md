# 既存サイトを pjas-ctrl の管理下に取り込む

`/var/www/<name>` に既に稼働中のサイトがある状態で、それを pjas-ctrl に登録する場合の手順。
一度しか通らない道だが、順番を間違えるとサイトが 403 になったり、
DB だけ新しくなってファイルが古いままという食い違いが起きる。

## 先に知っておくべき挙動

登録も初回デプロイも「既存ファイルを削除しない」設計だが、無害ではない。

| 場面 | 実際の挙動 |
|---|---|
| サイト登録 (`service.create`) | 既存ディレクトリはそのまま再利用し、中に `releases/` と `shared/` を作る。ただし **base ディレクトリ自体の所有者が `www-data:www-data`、パーミッションが 0755 に変更される** |
| サイト名 | `^[a-z0-9][a-z0-9-]{0,31}$` のみ。`example.com` や `MyApp` のようなディレクトリ名は登録できない |
| vhost 反映 | DocumentRoot は常に `/var/www/<name>/current/<docroot>`。**初回デプロイ前に反映すると存在しないパスを指すため 403/404 になる** (configtest は DocumentRoot の不在を検出しない) |
| 既存の Apache 設定 | pjas-ctrl が書くのは `pjas-<name>.conf` だけ。既存の `billing.conf` などは有効なまま残り、**同じ ServerName の vhost が二重に有効**になる。画面の「管理画面の管理下にありません」警告は `pjas-<name>.conf` の有無しか見ていないので、別名の既存 conf は検知されない |
| `current` という名前の実ディレクトリがある | `switchCurrent()` の `mv -Tf` が「ディレクトリを非ディレクトリで上書きできない」で失敗する。**失敗するのは after_deploy フックと configtest を通過した後**なので、`php artisan migrate --force` などは実行済みになる |
| 初回デプロイのヘルスチェック | 直前リリースが無いため**自動ロールバックが効かない**。失敗しても新リリースが公開されたままジョブが失敗する |
| 取り込み後の既存ファイル | `/var/www/<name>/` 直下に残ったまま参照されなくなる。`prune` は `releases/` 配下しか消さないので、放置すると永久に残る |
| ヘルスチェックの見え方 | vhost 未反映で既存サイトが応答している間は「正常」と表示される。**管理画面上は正常に見えるのに、実際は管理下に入っていない**状態が起こり得る |

## 手順

```bash
# 1. 既存ディレクトリを退避する
#    (特に current という名前のディレクトリがある場合は必須)
sudo mv /var/www/billing /var/www/billing.legacy

# 2. 管理画面または CLI からサイトを登録する
#    → 空の /var/www/billing に releases/ と shared/ が作られる

# 3. 引き継ぐものを shared へコピーする
sudo cp /var/www/billing.legacy/.env /var/www/billing/shared/.env
sudo cp -a /var/www/billing.legacy/storage/. /var/www/billing/shared/storage/
sudo chown -R www-data:www-data /var/www/billing/shared

# 4. 既存の Apache 設定を無効化する
sudo a2dissite billing

# 5. 初回デプロイ
sudo -u www-data php bin/pjasctl deploy billing /tmp/billing-1.8.2.zip --label v1.8.2

# 6. 公開設定を反映する
sudo -u www-data php bin/pjasctl vhost billing            # 差分確認
sudo -u www-data php bin/pjasctl vhost billing --apply
```

**4 と 6 の間はサイトが落ちる。** 切り替え時間を選ぶこと。
逆順（先に vhost 反映）にすると、DocumentRoot が存在しない状態を利用者に晒すことになる。

初回デプロイは自動ロールバックが効かないので、ヘルスチェックが失敗したら
`/var/www/billing.legacy` を戻して `a2ensite billing` で復旧する、という手を用意しておく。
退避したディレクトリは、新構成が数日安定してから削除する。

## 取り込まずに済ませる選択肢

既存サイトを今すぐ動かし続けたいだけなら、pjas-ctrl に登録しないという判断も妥当。
登録しただけで vhost を反映しなければ実害は base ディレクトリの権限変更だけだが、
「管理画面には出るが実際は管理下にない」状態は事故のもとなので、
取り込むなら最後まで通す、通せないなら登録しない、のどちらかにする。
