# 2026年11月14日の診断を受けた更新

[更新手順](upgrade.md) / [ドキュメント一覧](README.md)

対象はpjas-ctrlです。他のアプリ、ファイアウォール、Cloudflare、OS全体のPHP設定は変更しません。
systemd-analyzeの露出スコアは設定の評価であり、アプリの脆弱性件数や安全性の保証ではありません。

## 改修内容

- agentと管理HTTPに専用の `90-pjas-security.conf` を追加します。不要なカーネル・デバイス操作、SUID/SGID作成、危険なシステムコールとCapabilityを制限します。
- 回帰テストで確認した試験公開の環境設定ファイルの権限不備を修正し、`.env` を0600の一時ファイルから原子的に配置します。既存`.env`のシンボリックリンク先への書き込みも防ぎます。既に作成済みの試験環境は、更新後に試験公開を作り直すと反映されます。
- 管理HTTPのIP通信はループバックだけに限定します。agentはGitHub取得、通知、各サイトのヘルスチェックを行うため、外向き通信を維持します。
- 既存のunit本体、`pjas-cert.conf`、`system.control`配下の `MemoryHigh`・`MemoryMax`・`TasksMax`、Apache設定、PHP 8.4/8.5の設定、秘密情報・許可台帳を維持します。
- OS起動時は、保存状態が `ready` かつ初期化済みの隔離サイトを既存の起動処理へ通します。許可台帳・承認済みファイルのハッシュ、コード権限、秘密情報、依存コンポーネントの準備完了を確認し、消失した `/run` の設定と秘密情報のコピーを再作成します。
- `stopped`、`restore_hold`、初期化途中・失敗状態のサイトは自動起動しません。CA初期化、鍵の生成、バックアップ復元、発行再開の承認を自動実行しません。停止要求は実際の停止より前に保存し、中断後も停止意図を保持します。

agentは配置物の読み取り権限を保つため `UMask=0022` を維持します。秘密ファイルは各処理の明示的な0600/0700等の設定で保護します。全体を0077へ変えると、公開ファイルや実行用ディレクトリを他の専用ユーザーが読めなくなるためです。agentのroot実行と `NoNewPrivileges=no` は今回変更しません。

## 既存サーバーへの適用

以下は標準配置 `/opt/pjas-ctrl` の例です。作業履歴を確認し、公開・バックアップ・復元・初期化が実行中でない時間帯に進めます。agentと管理HTTPは短時間再起動します。

### 1. コードを更新する

```bash
cd /opt/pjas-ctrl
sudo git --no-pager status --short
sudo git pull --ff-only origin main
sudo git rev-parse HEAD
```

ローカル変更によってpullが失敗した場合は、その変更を退避して原因を確認します。`reset --hard` や設定ファイルの削除は不要です。`Spec.php.bak-*` 等の未追跡バックアップは、この更新処理から削除しません。

### 2. 変更予定と既存設定を確認する

```bash
sudo php8.4 bin/pjas-security-update --check
```

このコマンドは対象ファイル、コンポーネントの起動方式の移行対象、既存drop-in、書き込み許可、リソース制限を表示します。秘密情報の本文は表示しません。root専用の作業ロックと管理用ディレクトリは作成する場合があります。サービス設定・サイトデータは変更しません。

生成unitが承認済みハッシュと違う場合は停止します。コンポーネント側の追加drop-inは、`systemctl set-property` が `system.control` 配下に保存する `MemoryHigh`・`MemoryMax`・`TasksMax` だけのファイルを内容まで検証して維持します。それ以外の追加設定や、masked/linked等の通常と異なるenable状態は自動移行しません。現在の追加設定と台帳を照合してから対応します。agent側の `pjas-cert.conf` とメモリ制限はそのまま利用できます。

### 3. 適用する

11月14日に実機で確認した `pjas-component-pjas-cert-web.service.d/pjas-cert.conf` も、対象unit・パス・全設定値が一致するときだけ維持して移行できます。対象は `After=systemd-tmpfiles-setup.service`、`ReadWritePaths=/run/pjas-cert/coordination.lock`、`MemoryMax=256M`、`CPUQuota=100%`、`Environment=PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d:/etc/pjas-cert/php-ini` です。環境変数や書き込み先を一般的に許可するものではありません。変更・追加・欠落があれば再確認のため停止します。

この設定がある場合は共有ロックが通常ファイルとして存在することも確認します。既存のtmpfiles設定、PHP設定ディレクトリ、agent側の `/var/lib/pjas-cert` の書き込み許可は上書きしません。共有ロックはctrlから新規作成せず、OS起動時も既存のtmpfiles設定による準備が必要です。起動時復帰はその準備後に動きます。

同日に確認したCA・署名サービスの `pjas-cert.conf` と、3サービスの `zzzz-php85-migration.conf` も内容を限定して維持します。CA・署名側は共有ロックに加え `/run/pjas-cert/ca`・`/run/pjas-cert/sign` への書き込み指定を持ちます。PHP 8.5移行ファイルの指定は次のとおりです。

| コンポーネント | ExecStartの空指定に続く起動内容 | PHP_INI_SCAN_DIR |
| --- | --- | --- |
| web | `/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /run/pjas-components/pjas-cert-web/php-fpm.conf` | `/etc/php/8.5/fpm/conf.d:/etc/pjas-cert/php-ini` |
| ca | `/usr/bin/php8.5 bin/worker ca` | 追加指定なし |
| sign | `/usr/bin/php8.5 bin/worker sign` | 追加指定なし |

`ExecStart=` の空指定と置き換えの順序も検証し、逆順・省略・別コマンド・追加の環境変数は許可しません。既存の移行ファイルを保持するため、webのINI読み込み先は後から適用される8.5の指定が有効になります。ctrl本体のPHP 8.4や許可台帳・承認済みunitのハッシュを、8.5へ自動変換する処理ではありません。

`--check` はPHP 8.5実行ファイル、webのINI設定ディレクトリ、CA・署名サービスの既存実行用ディレクトリも確認します。存在しない場合は設定の削除や新規作成をせずに停止します。実行領域の所有者・権限は既存のtmpfiles設定で管理してください。

```bash
sudo php8.4 bin/pjas-security-update --apply
```

配置前の設定と起動状態は `/var/lib/pjas-control/security-updates/日時-識別子` に退避します。配置・構文検証・起動確認に失敗した場合は、旧設定と生成unitの自動起動設定を可能な範囲で戻し、失敗した旨を表示します。自動復旧の失敗も検出します。退避物はroot専用です。

承認済みの生成コンポーネントunitは `disable`（`--now`なし）にして、今動いているものは動かしたままにします。次回のOS起動は新しい `pjas-components-boot.service` が保存済み状態に従って担当します。管理者が割り当てた既存サービスのenable状態には触れません。その既存サービスの起動順序・runtime管理は管理者側の責任として残ります。

`pjas-components-boot.service` はenableだけを行い、その場で実行しません。動作中のCAに対して手動で繰り返し実行する用途ではありません。

**今回の限定更新では `bin/pjas-install-features --apply` を代用しないでください。** そのコマンドは従来どおりPHP 8.4のFPM・Apache設定も再配置します。PHP 8.5向けの独自設定がある場合は影響が異なります。限定更新スクリプトは再実行でき、以降も同じコマンドを使えます。

### 4. 実効設定と動作を確認する

```bash
sudo systemctl show --no-pager pjas-agentd.service pjas-control-web.service \
  -p Id -p ActiveState -p SubState -p DropInPaths \
  -p PrivateDevices -p ProtectKernelTunables -p ProtectKernelModules \
  -p ProtectControlGroups -p RestrictSUIDSGID -p CapabilityBoundingSet \
  -p UMask -p IPAddressAllow -p IPAddressDeny \
  -p MemoryHigh -p MemoryMax -p TasksMax -p ReadWritePaths
sudo systemctl is-enabled pjas-components-boot.service
sudo systemd-analyze security --no-pager pjas-agentd.service pjas-control-web.service
sudo systemctl --failed --no-pager
```

後から適用される独自drop-inがあると実効設定が変わるため、ファイルの配置成功だけで制限が有効になったとは判断しません。IP制限はカーネルのcgroup/BPF対応にも依存します。

管理画面へのログイン、サイト一覧、agent経由の状態取得、GitHub取得・テストサイト公開、Discord通知、pjas-certの各コンポーネント状態とHTTPS応答を確認します。稼働中のCAへの発行や状態復元を確認目的で実施する必要はありません。

## 次回OS再起動時の確認

この更新のためだけに即時rebootする必要はありません。予定された再起動後に確認します。

```bash
sudo systemctl status --no-pager --full pjas-components-boot.service
sudo journalctl -b -u pjas-components-boot.service --no-pager
sudo systemctl list-units 'pjas-component-*' --all --no-pager
sudo systemctl --failed --no-pager
```

ログの `PASS` は起動・依存ヘルスチェック完了、`SKIP` は停止・復元保留等による意図的なスキップ、`FAIL` は検証または起動失敗です。復帰はサイト単位にロックし、失敗したサイトがあっても他サイトの確認を続けます。失敗時にCA初期化をやり直さず、管理画面の状態・許可台帳・承認済みunitの変更有無を確認してください。

生成unitを管理者が直接 `systemctl start` する操作や、外部サービスからの独自依存関係による起動を禁止する機構は今回追加していません。隔離サイトの起動・停止はctrlから行ってください。

## 検証の範囲

### PHP 8.5の選択肢が表示されない場合のコード更新

サイトの新規登録・設定・一括登録のプルダウンに `php8.5-fpm` を追加しました。Webとagentの保存時検証、v2マニフェスト・JSON Schema、Composer/Artisanの許可済み公開後処理も8.5に対応します。既存サイトのランタイムやctrl本体のPHPバージョンは自動変更しません。既定値も従来どおりです。

この修正の反映にはコード取得とagentの再起動だけで十分です。DB移行や全機能インストールは不要です。公開・バックアップ等の処理が実行中でない時間に実行してください。

```bash
(
  set -e
  cd /opt/pjas-ctrl
  sudo git pull --ff-only origin main
  sudo systemctl restart pjas-agentd.service
  sudo systemctl is-active pjas-agentd.service
)
```

ブラウザーで管理画面を再読み込みしてください。PHP 8.5サイトを実際に動かすには、対象ホストにPHP 8.5 FPM・必要拡張・CLIが導入済みであることと、サイトの公開設定が対象FPMを参照することを確認します。既存confを維持する公開設定の部分更新は、ランタイムを選び直すだけで既存のFPM指定を自動変更する操作ではありません。

`tests/security_update.php` は更新対象の限定と改変unitの拒否、`tests/components_workflow.php` はruntime消失後の復帰・許可台帳とunit改変時の拒否・停止と復元保留の維持を検証します。GitHub Actionsでは専用の非特権ユーザーでPHP/Python回帰テストを実行します。

これらの回帰テストは本番systemdのサンドボックス内での全操作を保証するものではありません。Capabilityやシステムコール制限と、実ホスト固有のApache・pjas-cert設定との組み合わせは、適用後に上記の実機確認が必要です。

設定の参考: [systemd.exec](https://www.freedesktop.org/software/systemd/man/256/systemd.exec.html)、[systemd.resource-control](https://www.freedesktop.org/software/systemd/man/256/systemd.resource-control.html)。
