# pjas-ctrl アーキテクチャ設計

マイクロサービス群 `pjas`（`/var/www/<subdomain>` 配下に置かれる PHP / 静的サイト等）の
デプロイ・Apache 設定・運用を一元管理するコントロールプレーン。

---

## 1. 設計方針

| 原則 | 内容 |
|---|---|
| 権限分離 | Web UI は専用の `pjas-control` で動き **root を持たない**。特権操作は専用デーモン `pjas-agentd` (root) に Unix ソケット経由で依頼する |
| ホワイトリスト実行 | エージェントは「操作名 + 型付きパラメータ」しか受け取らない。任意コマンド文字列は一切通さない |
| 原子的デプロイ | `releases/<timestamp>` に展開 → 検証 → `current` シンボリックリンクを付け替え。ロールバックはリンクを戻すだけ |
| 設定はテンプレート生成 | Apache vhost は手書きさせず、テンプレート + 変数から生成 → `apachectl configtest` → `graceful` |
| 全操作を監査 | 誰が・いつ・何を・結果 を追記専用ログに記録 |
| 単一障害時に本番を壊さない | configtest 失敗 / ヘルスチェック失敗 は自動で直前状態へ復帰 |

---

## 2. 全体構成

```
                 ┌─────────────────────────────┐
   運用者 ──────▶│  pjas-ctrl Web UI (ctrl.pjas.example)
                 │  PHP 8.4 / セッション + RBAC │
                 └──────────────┬──────────────┘
                                │ HTTP(S) 内部 API
                 ┌──────────────▼──────────────┐
                 │  pjas-ctrl (pjas-control)    │
                 │  ジョブ登録 / 検証 / 監査     │
                 └──────┬───────────────┬──────┘
                        │ SQLite        │ Unix domain socket
                        │ (状態/監査)    │ /run/pjas-agent.sock
                 ┌──────▼──────┐ ┌──────▼─────────────────┐
                 │ pjas.db     │ │ pjas-agentd (root)      │
                 │ services    │ │ ・ファイル配置/権限      │
                 │ releases    │ │ ・vhost 生成/a2ensite    │
                 │ jobs        │ │ ・apachectl configtest   │
                 │ audit       │ │ ・systemctl reload       │
                 └─────────────┘ │ ・共通 TLS 証明書の適用  │
                                 └──────┬──────────────────┘
                                        │
                    /var/www/<subdomain>/{releases,shared,current}
                    /etc/apache2/sites-available/pjas-<name>.conf
```

### コンポーネント

- **Web UI**: 管理画面（本ドキュメントのモックが対象）
- **API**: REST。UI と CLI (`pjasctl`) の共通入口
- **Worker**: ジョブキュー（デプロイは非同期。UI は SSE でログを追従）
- **pjas-agentd**: 特権操作の唯一の実行者。systemd ユニット、`ProtectSystem=strict` + 必要パスのみ書込許可
- **pjasctl**: CI から叩く CLI（API トークン認証）

---

## 3. ディレクトリ規約

```
/var/www/<subdomain>/
├── releases/
│   ├── 20260815-101500/      # 展開済みリリース
│   └── 20260814-183012/
├── shared/                   # リリース間で共有（永続）
│   ├── .env
│   ├── storage/
│   └── uploads/
├── current -> releases/20260815-101500
└── .pjas.yml                 # サービス定義（リポジトリ同梱）
```

`.pjas.yml` の例:

```yaml
name: billing
domain: billing.pjas.example
runtime: php8.4-fpm          # php8.4-fpm | static
docroot: public              # リリース内の DocumentRoot
shared:
  - .env
  - storage
health:
  path: /healthz
  expect_status: 200
hooks:
  after_deploy:
    - composer install --no-dev -o   # 許可リスト内のコマンドのみ
keep_releases: 5
```

---

## 4. デプロイ・パイプライン

```
[1] 取り込み      ZIP/tar.gz アップロード、または Git ref 指定
[2] 検疫          サイズ/拡張子/パストラバーサル検査、SHA-256 記録
[3] 展開          releases/<ts> に展開（www-data:www-data, 0644/0755）
[4] リンク        shared/* をリリース内へシンボリックリンク
[5] フック        after_deploy を許可リスト実行（タイムアウト付き）
[6] 事前検証      php -l 一括、docroot 存在確認、apachectl configtest
[7] 切替          current を新リリースへ原子的に付け替え（ln -sfn + mv）
[8] リロード      php-fpm reload / apache2 graceful
[9] ヘルス        health.path を N 回ポーリング
[10] 判定         失敗 → [7] を直前リリースへ戻し reload → ジョブを failed
[11] 掃除         keep_releases を超える古いリリースを削除
```

すべての段の標準出力はジョブログとして保存し、UI に SSE でストリームする。

---

## 5. Apache 設定管理

テンプレート `templates/vhost.php8-fpm.conf.twig` から生成:

```apache
<VirtualHost *:443>
    ServerName {{ domain }}
    DocumentRoot /var/www/{{ name }}/current/{{ docroot }}
    <Directory /var/www/{{ name }}/current/{{ docroot }}>
        AllowOverride {{ allow_override }}
        Require all granted
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/{{ runtime }}.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog  /var/log/apache2/{{ name }}-error.log
    CustomLog /var/log/apache2/{{ name }}-access.log combined
    SSLEngine on
    SSLCertificateFile    /etc/ssl/cloudflare/memo.takusumi.work.pem
    SSLCertificateKeyFile /etc/ssl/cloudflare/memo.takusumi.work.key
</VirtualHost>
```

適用フロー: **生成 → 差分プレビュー（UI で承認）→ 書き出し → `a2ensite` → `configtest` →
成功なら `graceful` / 失敗なら旧ファイルへ復元**。
生成ファイルには `# managed by pjas-ctrl — do not edit` ヘッダを入れ、手編集を検出したら警告。

TLS は全サイト共通の Cloudflare Origin CA ワイルドカード証明書を使用する。

---

## 6. データモデル（SQLite）

| テーブル | 主なカラム |
|---|---|
| `services` | id, name, domain, runtime, docroot, status, keep_releases, created_at |
| `releases` | id, service_id, tag, source(zip/git), sha256, size, deployed_by, state(active/archived/rolled_back), created_at |
| `jobs` | id, service_id, type(deploy/rollback/vhost/tls/restart), state, log_path, started_at, finished_at |
| `vhosts` | id, service_id, rendered_hash, applied_at, applied_by |
| `users` | id, email, password_hash, role(owner/operator/viewer), totp_secret |
| `audit_log` | id, user_id, action, target, params_json, result, ip, at |
| `api_tokens` | id, name, hash, scopes, last_used_at |

---

## 7. ルーティング（実装済み。必要ロールを併記）

```
GET  /login                        public   ログイン画面
POST /login                        public   ログイン（CSRF 必須）
POST /logout                       viewer

GET  /                             viewer   ダッシュボード
GET  /services                     viewer   一覧
GET  /services/new                 owner    登録フォーム
POST /services                     owner    登録（agent が雛形ディレクトリを作成）
GET  /services/{name}?tab=…        viewer   詳細（overview/releases/vhost/files/jobs/settings）
POST /services/{name}/settings     owner    設定更新
POST /services/{name}/health       viewer   ヘルスチェック実行
POST /services/{name}/vhost        owner    vhost 適用（configtest → graceful）
POST /services/{name}/rollback     operator ロールバック

GET  /deploy                       operator デプロイ画面
POST /deploy                       operator デプロイ実行（アップロード / git ref）
GET  /jobs/{id}                    viewer   ジョブ詳細
GET  /jobs/{id}/stream             viewer   ジョブログ（SSE）
GET  /audit                        operator 監査ログ
```

エージェント RPC の op は `AgentClient::OPS` と agent の登録表が唯一の入口:
`service.create` / `service.destroy` / `deploy.start` / `deploy.rollback` /
`apache.render` / `apache.apply` / `apache.reload` / `fpm.reload` /
`fs.list` / `fs.read` / `fs.write` / `health.check` / `cron.apply` / `cron.delete` / `ping`。

---

## 8. セキュリティ

- **特権境界**: Web ↔ agent は型付き RPC。`{"op":"apache.reload"}` のように op は列挙型
- **パス封じ込め**: すべてのパスを `realpath` で解決し `/var/www/` 配下であることを検証
- **アップロード検疫**: 実行権限の一括除去、`.htaccess` / `*.phar` を既定で拒否（許可制）
- **認証**: セッション + TOTP、CI は scope 付き API トークン
- **RBAC**: viewer=閲覧, operator=デプロイ/ロールバック, owner=vhost/TLS/ユーザー管理
- **承認フロー（任意）**: 本番サービスは vhost 変更に第二承認者を必須にできる
- **管理面の露出**: ctrl 自身の vhost は IP 制限 / mTLS / VPN 限定を推奨

---

## 9. リポジトリ構成（実装時）

```
pjas-ctrl/
├── public/            # ctrl の DocumentRoot（index.php + assets）
├── app/
│   ├── Http/          # Router, Auth(RBAC), Csrf, View, Controllers/
│   ├── Domain/        # ServiceRepo, ReleaseRepo, JobRepo, Deployer, VhostRenderer, Hooks
│   ├── Agent/         # AgentClient（許可 op のみ送信）
│   └── Support/       # Config, Db, Audit
├── agent/             # pjas-agentd（root, PHP-CLI）
│   ├── lib/           # Spec（入力検証）, Proc（非シェル実行）, Paths, JobLog
│   ├── ops/           # Service, Deploy, Vhost, Fs, Tls, Health
│   ├── config.php
│   └── pjas-agentd.php
├── views/             # 画面テンプレート
├── bin/{pjas-init,pjasctl}
├── db/schema.sql
├── systemd/           # pjas-agentd.service, 管理画面用 Apache 設定
├── tests/vhost_parity.php
└── docs/{architecture.md,install.md}
```

---

## 10. 実装状況

実装済み: サービス登録・設定、ZIP / tar.gz アップロードと git ref からのデプロイ、
releases/current 切替とロールバック、vhost 生成と差分承認・configtest・graceful、
ジョブログ SSE、ヘルスチェックと自動ロールバック、リリース掃除、Cloudflare Origin CA 証明書、
ファイルブラウザ、監査ログ、RBAC、CSRF、CLI (`pjasctl`)。

未実装（次の段）: TOTP 二要素認証、API トークン認証での外部 API、shared/.env の画面編集、
vhost 変更の第二承認フロー、リリースごとのメトリクス表示。


## 追加した運用機能

GitHub App・暗号化バックアップ・公開前の差分確認・日時指定公開・試験公開・サイトごとの権限・TOTPなどの実装と初回準備は [運用機能の使い方](operations.md) を参照してください。管理画面は専用のPHP-FPMとHTTPサービスへ分け、本番サイトのwww-dataを特権RPCグループから外します。GitHub Appの秘密鍵・バックアップ用鍵はroot専用領域、TOTPの暗号鍵は管理画面専用領域へ保管します。
