# 復旧

[目的別ガイド](../README.md) / [保守手順](maintenance.md) / [保存先・CLI](reference.md)

## 何を戻すか決める

| 操作 | 戻す範囲 |
| --- | --- |
| コードの切り戻し | リリースのコード。共有データとDB構造は戻さない |
| サイトデータの復元 | 指定バックアップの共有データ・DB。書き込み元の停止が必要 |
| CA状態の復元 | 発行・失効履歴等を含む永続領域。専用の整合性検査と発行停止が必要 |
| ctrl自身の更新復旧 | 退避したctrlのコード・設定・管理DB。管理対象サイトのデータは戻さない |

## バックアップを用意する

サイト詳細の「バックアップ・運用」で保存結果、保持数、暗号化バックアップを確認します。復元時は対象サイト・日時・対象データを確認し、HTTPだけでなく常駐処理・cron・外部の書き込みも停止します。復元後は停止を維持し、ログイン・DB更新・アップロードなどを検査してから再開します。

SQLiteは本体に加えてWALへ未反映の更新を持つことがあります。稼働中に本体だけコピーせず、バックアップAPIまたは書き込み停止後の整合性保存を使います。`-wal`や`-shm`を原因確認なしに削除しないでください。

ctrl全体の退避には次を含めます。秘密情報を含むためroot専用・暗号化保管にします。

- `config.php`、`agent/config.php`、現在のコミット。
- 管理DB、二段階認証用の鍵、root管理情報。
- Apache・FPM・systemd・cronの設定、リポジトリ・常駐処理・公開停止の台帳。
- コンポーネント許可台帳と、割り当てた永続領域・秘密情報。

### 管理DBの整合性保存

標準パスの例です。独自のDBパスはWeb設定で確認して置き換えます。SQLiteのバックアップAPIはWALの更新も含む整合したDBを作ります。これだけでは設定・鍵・root管理情報の退避にはなりません。

```bash
PJAS_BACKUP_DIR="/var/backups/pjas-ctrl/$(date -u +%Y%m%dT%H%M%SZ)"
sudo install -d -m 0700 "$PJAS_BACKUP_DIR"
sudo php8.5 -r '$src=new SQLite3($argv[1],SQLITE3_OPEN_READONLY); $dst=new SQLite3($argv[2]); if(!$src->backup($dst)){exit(1);} $dst->close(); $src->close(); chmod($argv[2],0600);' \
  /var/lib/pjas/pjas.db "$PJAS_BACKUP_DIR/management.sqlite"
```

## ctrlが503で開けない

公開Apache → `127.0.0.1:8184`の管理用HTTP → `/run/php/pjas-control.sock`の順で確認します。更新中なら終了を待ち、更新失敗なら次の節を先に確認します。

```bash
sudo systemctl status pjas-control-web php8.5-fpm pjas-agentd --no-pager -l
sudo tail -n 30 /var/log/apache2/pjas-ctrl-error.log
sudo tail -n 30 /var/log/pjas/control-web-error.log
sudo ls -l /run/php/pjas-control.sock
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8184/
```

管理用HTTPが停止していれば、FPMを確認したうえで起動します。

```bash
sudo systemctl start php8.5-fpm.service
sudo systemctl start pjas-control-web.service
sudo systemctl --failed --no-pager
```

正常に停止したサービスは`--failed`に表示されません。PHP-FPMの停止に伴い管理用HTTPも停止する場合があります。管理用PHPが8.5以外なら`/etc/pjas-control/php-version`とサービス定義を確認し、コマンドを合わせます。

## ctrlの更新が失敗・中断した

専用サービスは管理画面が切れても継続します。状態、退避先、サービスの記録を確認します。

```bash
sudo php8.5 /var/lib/pjas-control/updater/bin/pjas-control-update status
sudo systemctl list-units 'pjas-control-update@*' --all --no-pager
```

`rollback_complete=true`なら旧コード・管理DB等の復旧が完了しています。再ログインし、失敗原因を調べてから再確認します。`running`のままサービスが終了した場合や自動復旧が未完了の場合は、他の更新・Git操作を行わず、表示されたIDで復旧します。

```bash
PJAS_UPDATE_ID=状態に表示された32桁のID
sudo journalctl -u "pjas-control-update@$PJAS_UPDATE_ID.service" -n 100 --no-pager
sudo php8.5 /var/lib/pjas-control/updater/bin/pjas-control-update recover "$PJAS_UPDATE_ID"
```

失敗の詳細はroot専用の`/var/lib/pjas-control/control-update-error-ID.json`と`control-update-command-error.json`に保存します。自動復旧も失敗した場合は`control-update-recovery-error-ID.json`を確認します。秘密情報を含む可能性があるため、そのまま共有しないでください。

退避は`/var/lib/pjas-control/control-updates/ID`、更新基盤は同領域の`updater`にあります。更新用の停止情報を先に削除して公開しないでください。退避が存在しない段階で中断した場合はコード・DB変更の有無を確認し、サービスを手動復帰してから管理者が状態を整理します。

## v2の構成適用が中断した

再適用やサービス起動の前に、SSHから適用の状態を確認します。画面に表示された操作IDと同じIDを使います。

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-component-recover pjas-cert
```

状態が `pending` の場合は、表示されたIDを控えてから次を実行します。

```bash
sudo php8.5 bin/pjas-component-recover pjas-cert 操作IDをここに入力
sudo php8.5 bin/pjas-host-check --drift=pjas-cert
```

`rolled_back` になれば、生成済み設定と承認状態の復旧が完了しています。CAの永続データは保持されます。稼働が必要な場合は構成を確認してから実行環境で明示的に起動します。設定ファイルに別の変更があれば復旧は停止します。失敗時は対象の変更を確認し、処理済みのファイルも含めて同じIDで再試行できます。

## サイト移行が失敗した

新しい計画で上書きせず、失敗した単独移行IDを確認します。

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-site-upgrade status "$PJAS_UPGRADE_ID"
```

`prepared`なら、停止中に原因を直し、同じIDで`activate --confirm-activation`を再実行できます。`quiescing`・`isolated`など再開可能な段階は同じIDの`apply`を使います。隔離処理中の失敗は途中状態を調べ、必要なら切り戻します。

```bash
sudo php8.5 bin/pjas-site-upgrade rollback "$PJAS_UPGRADE_ID" --confirm-rollback
```

これは設定・権限・関連付けの復旧です。アプリの共有データそのものを過去へ戻す操作ではありません。

### 待機ジョブが残る

```bash
sudo php8.5 bin/pjas-site-upgrade jobs example-site
```

agentの記録が見つからないことだけで「未実行」と断定しません。プロセス、サービス、実行記録を照合します。DBの全待機ジョブを一括で失敗扱いにせず、対象を特定して退避・修復します。

### ハードリンクで止まる

```bash
sudo python3 agent/lib/isolate_tree.py --check /var/www/example-site
```

サイト内の同一リリースに全リンク先がある通常ファイルだけ、全書き込み元を止めてから独立ファイルへ分離できます。

```bash
sudo python3 agent/lib/isolate_tree.py --split-internal-hardlinks \
  /var/www/example-site --writers-stopped
```

外部・共有データ・別リリースとのリンク、特殊ファイルは自動修復対象外です。

### 権限・SQLiteで止まる

現在のサービスの実行ユーザー、環境変数ファイル、DBの実際のパスを確認します。リリース内にDBがあるとは限りません。切り戻し後に専用ユーザー所有のセッション・一時領域・WAL/SHMだけ残る場合もあります。

書き込み元を止め、所有者・ACL付きで退避し、問題のパスと本来のユーザーを特定して修復します。サイト全体の`chmod 777`や一括所有者変更は避けます。FPMの`security.limit_extensions`エラーでは、実行ファイル名へAPIのパスが付加されていないか確認し、拡張制限を緩めて回避しません。

## CA状態を戻す

「実行環境」で対象バックアップの復元影響を確認します。復元後も発行停止を維持し、DB・鍵・証明書の整合性、発行・失効履歴の欠落、外部署名処理との二重稼働がないことを検査します。履歴の欠落を明示確認して停止を解除し、起動は別操作で行います。PHP更新やコード公開のために初回セットアップを再実行しません。

## 公開前の一括サイト移行を復旧する

一括移行の全サイトを公開前に確認したい場合は、`batch-plan` の後に
`batch-prepare ID --accept-standard-apache --external-processes-stopped` を実行します。
対象サイトは停止したままです。管理画面と agent が停止していても、管理DBと
ホスト上の設定・ジャーナルにアクセスできれば、サーバー上で状態を確認できます。

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-site-upgrade batch-status "$PJAS_BATCH_ID"
sudo php8.5 bin/pjas-site-upgrade batch-rollback "$PJAS_BATCH_ID" --confirm-rollback
```

切り戻しは着手済みサイトを逆順で処理し、設定と関連付けを復旧します。
アプリデータの復元は別手順です。公開済みサイトを含む場合は一括切り戻しを
開始できません。全サイトの準備を確認して公開する場合は、同じ ID で
`batch-apply` を実行します。切り戻した後に再移行する場合は新しい計画を作成します。

### 複数の移行計画を同じ変更IDで準備・復旧する

未着手の単独移行と一括移行を組み合わせる場合は、次のコマンドで
調整用の変更IDを作ります。一括計画に含めたAPI設定も準備時に反映されます。
重複するサイトを含む計画は登録できません。

```bash
sudo php8.5 bin/pjas-site-upgrade change-plan "site:$SITE_UPGRADE_ID,batch:$PJAS_BATCH_ID"
sudo php8.5 bin/pjas-site-upgrade change-prepare "$CHANGE_ID" \
  --accept-standard-apache --external-processes-stopped
sudo php8.5 bin/pjas-site-upgrade change-status "$CHANGE_ID"
```

`CHANGE_ID` は最初のコマンドが返す `id` です。準備が中断した場合は
`change-rollback "$CHANGE_ID" --confirm-rollback` で着手済みの計画を逆順に戻します。
復旧が中断しても同じコマンドを再実行できます。
準備を完了し公開する場合は、各計画の `activate` または `batch-apply` を使います。
公開を開始した後は、同じ変更IDでまとめて切り戻せません。

### 標準のApache公開設定の変更が中断した場合

通常の公開設定適用中に agent が強制終了した場合は、サーバー上で
復旧記録を確認できます。対象の設定ファイルと有効化リンクを変更前へ
戻し、Apacheの構文検査と再読み込みが成功してから記録を解除します。
別の設定変更を検出した場合は復旧を中止します。

```bash
cd /opt/pjas-ctrl
sudo php8.5 bin/pjas-apache-recover status
sudo php8.5 bin/pjas-apache-recover rollback --confirm-rollback
```

復旧の記録はサーバーの管理領域に保存されます。コマンドは管理画面や
agentへの接続を必要としません。継承元の入れ替えや公開済みリリースの
切り戻しには、それぞれの復旧手順を使ってください。

### サイト移行と独立したApache設定をまとめて変更する

`apache-plan SITE` は、公開済みの標準VirtualHostについて、管理DBに保存された
サイト設定を反映する差分と計画IDを返します。継承設定、競合するVirtualHost、
v2コンポーネントは対象外です。移行対象とは別のサイトを指定し、Apacheの計画は
一件だけ最後に追加します。

```bash
sudo php8.5 bin/pjas-site-upgrade apache-plan OTHER_SITE
sudo php8.5 bin/pjas-site-upgrade change-plan "site:$SITE_UPGRADE_ID,apache:$APACHE_PLAN_ID"
sudo php8.5 bin/pjas-site-upgrade change-prepare "$CHANGE_ID" \
  --accept-standard-apache --external-processes-stopped
```

`APACHE_PLAN_ID` と `CHANGE_ID` にはそれぞれの計画コマンドが返したIDを設定します。
準備中に、移行サイトは停止状態になり、独立したApache設定は再読み込みされます。
確定前に戻す場合は `change-rollback "$CHANGE_ID" --confirm-rollback` を使います。
変更を維持する場合は `change-commit "$CHANGE_ID" --confirm-commit` で確定してから、
移行サイトを `activate` または `batch-apply` で公開します。

### Apache設定とctrl自己更新を同じ変更IDで管理する

更新機構の最新版を `pjas-control-update install` で登録してから実行します。

```bash
sudo php8.5 bin/pjas-control-update review
sudo php8.5 bin/pjas-site-upgrade change-plan "apache:$APACHE_PLAN_ID,ctrl:$REVIEW_ID"
sudo php8.5 bin/pjas-site-upgrade change-prepare "$CHANGE_ID" \
  --accept-standard-apache --external-processes-stopped
sudo php8.5 bin/pjas-site-upgrade change-commit "$CHANGE_ID" --confirm-commit
sudo php8.5 bin/pjas-control-update status
```

`REVIEW_ID` は更新内容を確認した際のIDです。確認には有効期限があります。
`change-commit` で更新サービスを起動し、更新成功後に同じコマンドを再実行して
Apache設定を確定します。更新失敗時は、更新側でコードと管理DBの復旧完了を
確認してから `change-rollback "$CHANGE_ID" --confirm-rollback` を実行します。
更新が中断した場合は先に独立した更新ランナーの `recover` を使います。
成功した更新を管理DBごと過去へ戻すと、その後の操作を失うため、
この組み合わせでは成功後の一括切り戻しはできません。
管理DBを使うサイト移行とctrl更新は別の変更IDで実行します。
