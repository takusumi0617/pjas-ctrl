# CLIによるサイト移行

[更新手順](upgrade.md) / [隔離の構成](site-isolation.md)

通常サイトのPHP更新・隔離移行を画面操作なしで行います。常駐プログラムの関連付けを解除する操作は不要です。実行は管理サーバーのrootに限定します。PHP・Apacheの専用ユーザー化など、[更新手順](upgrade.md)のサーバー側準備を先に完了してください。

## 1. 計画を作成する

以下は `pjas-chat` をPHP 8.5へ移行する例です。実際のサイト名へ置き換えます。

```bash
cd /opt/pjas-ctrl
sudo -u pjas-control php8.5 bin/pjasctl services

PJAS_UPGRADE_ID="$(sudo php8.5 bin/pjas-site-upgrade plan pjas-chat --php=8.5 --id-only)"
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"
```

計画はroot専用ファイルへ保存します。この段階ではサイト・常駐処理・cronの稼働状態を変更しません。公開出力には移行ID・対象unit名・cron ID・PHP・状態を表示し、コマンドや環境変数の機密値は表示しません。journalの内容には機密値が含まれる可能性があるため共有しないでください。

v2サイト、サイト外のWorkingDirectory、simple/exec以外の常駐処理、特権付きの付随コマンド、複雑なシェル内に残る旧PHP指定、DBにないcronなどは自動移行を拒否します。独自Apache設定の保持・変換も行わないため、標準設定で動く構成を先に準備します。

## 2. 外部処理を停止し、移行を適用する

外部のcron・タイマー・ワーカーや、ctrl外からのデプロイを停止します。スクリプトは登録された常駐処理・cronを自動で停止します。既に実行されたcron等が残る場合は、PIDを表示して停止するため、その終了を待って同じコマンドを再実行します。他サイトのプロセスを一括killしません。

```bash
sudo php8.5 bin/pjas-site-upgrade apply "$PJAS_UPGRADE_ID" \
  --accept-standard-apache \
  --external-processes-stopped
```

この操作は次を行います。

1. 計画後の設定・unit・公開リリースの変更と、実行中作業を確認する。
2. site/daemonロックと永続的な移行中ガードを設定する。
3. 対象unitに一時的な起動禁止条件を追加し、サイトを一時停止する。
4. cronのDB設定・定義を退避して無効化し、登録ワーカーを停止する。
5. 旧FPMを再起動して実行中のPHP要求を終了し、ACLと設定を退避する。
6. 暗号化データバックアップを取得し、既存の隔離エンジンでPHP・権限を切り替える。
7. 元のunit名と設定を維持して専用UID/GID・PHP・権限制限のdrop-inを追加する。
8. 関連付けと管理DBのPHP・フック・cronを更新し、停止状態で完了する。

共有FPMの再起動は、そのPHPバージョンを使う他サイトへも影響します。独自Apache設定・別名ドメイン・プロキシは退避して標準設定へ置き換えます。これらを了承するフラグがない場合は変更しません。

元の環境変数・EnvironmentFile・メモリ制限・再起動設定は保持します。補助グループと追加capabilityは除去します。専用ユーザー化で利用できなくなる外部ファイルやポートがある場合は、事前に権限設計を確認してください。移行後のunitは同じ名前で操作できますが、生成された隔離drop-inを旧ユーザーに戻さないでください。

## 3. 状態を確認する

```bash
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"
# 移行IDを忘れた場合、進行中の移行はサイト名でも確認できます。
sudo php8.5 bin/pjas-site-upgrade status pjas-chat

sudo php8.5 bin/pjas-sites-check
```

`prepared`なら隔離・DB更新は完了し、サイト・ワーカー・cronを停止した状態です。他に未移行サイトがあると `pjas-sites-check` は終了コード1になります。対象サイトの結果を確認してください。

## 4. 元の稼働状態へ戻す

```bash
sudo php8.5 bin/pjas-site-upgrade activate "$PJAS_UPGRADE_ID" --confirm-activation
```

実効User/Groupを確認して、元々稼働していたワーカーだけを起動します。元々公開中だったサイトを再開してHTTP検査を行い、成功後にcronを元の有効状態へ戻します。元々停止中だったサイト・ワーカーは勝手に起動しません。

起動・HTTP検査で失敗した場合は、サイト・ワーカー・cronを再停止します。修正後に同じ `activate` を再実行できます。停止処理自体に失敗した場合は、その旨を表示するため、ログと実際のサービス状態を確認してください。

HTTP検査は指定されたhealth_path/statusの確認です。ログイン・DB更新・アップロードなどの実機能も、公開後に確認します。PHP 8.6も、対象CLI/FPMの導入・互換性検証後に `--php=8.6` で同じ手順を使います。

## 中断・失敗時

```bash
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"

# quiescing / isolated / prepared の再実行可能な段階を再開する場合
sudo php8.5 bin/pjas-site-upgrade apply "$PJAS_UPGRADE_ID" \
  --accept-standard-apache --external-processes-stopped

# 公開前の設定へ復旧する場合
sudo php8.5 bin/pjas-site-upgrade rollback "$PJAS_UPGRADE_ID" --confirm-rollback
```

`isolating`で中断した場合は、隔離エンジンを盲目的に繰り返さず `rollback` を使用します。`activating`なら同じ `activate` を再実行します。`rollback`自体の中断も同じコマンドで再試行できます。

復旧対象は、記録したApache/FPM設定・所有者/ACL・cron・関連付け・管理DBの設定・unit追加設定・元の起動状態です。アプリのデータ内容は巻き戻しません。作成した専用ユーザーも削除せず再試行用に残します。公開完了後の `active` は自動rollback対象外です。

計画後や移行中の別操作によるDB変更は上書きしません。実行中の移行があるサイトはagent経由の操作を拒否します。エラー時はjournalとサービス状態を確認し、別操作と混在させず復旧してください。

## 特殊ファイル・ハードリンクの診断

```bash
sudo python3 agent/lib/isolate_tree.py --check /var/www/対象サイト名
```

読み取り専用で、ファイルの内容は表示せず、対象パス・種類・リンク数を表示します。シンボリックリンクは追跡しません。問題があれば終了コード1です。新しいapplyはサイトを停止する前にも同じ検査を行います。

既に `isolating` で失敗した移行は、まず同じIDの `rollback` で設定を復旧します。対象が分かるまでファイルを削除したり、一括でハードリンクを解除したりしないでください。外部パスと同じinodeの可能性があるため、保護判定を無効化して通すことも避けます。

同じリリース内のesbuild等で、全リンクが検査対象内に存在すると確認できた場合は、次の明示的な操作で独立した通常ファイルへ分離できます。npm install・ビルド・公開等の書き込み処理を停止して実行してください。

```bash
sudo python3 agent/lib/isolate_tree.py --split-internal-hardlinks /var/www/対象サイト名 --writers-stopped
sudo python3 agent/lib/isolate_tree.py --check /var/www/対象サイト名
```

内容・実行ビット・所有者・時刻・拡張属性をコピーし、一時ファイルから原子的に置き換えます。外部リンク、sharedとのリンク、別リリース間のリンク、特殊ファイルは拒否します。失敗した隔離処理は先にrollbackし、分離後は新しい移行計画を作成してください。
