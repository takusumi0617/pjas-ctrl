# 失敗したときの切り分け

まずジョブログを読む。どこまで進んだかが分かれば、本番の現状も分かる (`pipeline.md` の表)。

```bash
sudo -u www-data php bin/pjasctl logs <job-id>
```

## `エラー: アーカイブが見つかりません`

`pjasctl` に渡したパスが www-data から読めていない。`/root` 配下や、権限が 600 の
ファイルを指していることが多い。`/tmp` に置いて `chmod 644` する。

## `.zip / .tar.gz / .tgz の圧縮ファイルだけアップロードできます`

拡張子で判定している。`tar.bz2` や、拡張子を変えただけのファイルは通らない。

## ヘルスチェックに失敗して自動ロールバックされた

サイトは前のバージョンに戻っている（追加の切り戻しは不要）。よくある原因:

1. **`.env` などの引き継ぎ漏れ** — アーカイブに `.env` を含めてしまうと shared リンクと
   衝突する。逆に `shared_paths` に登録し忘れていると新リリースに設定が無い。
   `pjasctl services` の後、Web UI の「設定」タブで引き継ぎ対象を確認する。
2. **マイグレーション未実行** — `php artisan migrate --force` を after_deploy に入れているか。
3. **ヘルスチェック先の設定ミス** — 動作確認に使うページと期待ステータスが実態と合っているか。
   アプリが 302 を返す構成なのに 200 を期待している、など。

## after_deploy フックが失敗しました

そのコマンドを新リリースのディレクトリで手元再現する。フックは web ユーザーとして、
`HOME` を shared に向けて実行されるため、`composer` や `npm` のキャッシュ権限で
落ちることがある。許可リストに無いコマンドを設定しようとした場合は、そもそも保存時に弾かれる。

## apachectl configtest に失敗しました

**Apache は元の設定のまま動いている。本番は無事。** 追加ディレクティブの記述ミスがほぼ全て。
`pjasctl vhost <service>` で生成内容の差分を見て、該当行を直す。

## agent に接続できない / 公開機能が「利用できません」

```bash
systemctl status pjas-agentd
sudo journalctl -u pjas-agentd -n 50
ls -l /run/pjas-agent.sock          # グループが pjas、モード 0660 であること
id www-data                         # pjas グループに入っていること
```

`config.php` (web) と `agent/config.php` のソケットパス・`www_root`・`upload_dir`・`job_dir` が
食い違っていても繋がらない。導入直後の不調はまずここを疑う (`docs/install.md`)。

## デプロイは成功したのに 403 / 404 になる

`docroot` の指定と、アーカイブの構造がずれている。アーカイブのトップに余分な一段
(`myapp/public/...`) があると、`current/public` が存在せず 403 になる。
`pjasctl services` で対象を確認し、Web UI の「ファイル」タブで `current` の中身を見る。
