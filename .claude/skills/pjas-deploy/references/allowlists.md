# 許可リスト

web 側と agent 側の両方で検証される。web を通さず agent に直接投げても同じ検査に掛かる
(`agent/lib/Spec.php`)。ここに無いものは「設定できない」ので、回避策を考えるのではなく
「この処理は pjas-ctrl のフックでは実行できない」とユーザーに伝えること。

## after_deploy フック (`app/Domain/Hooks.php`)

正規表現で完全一致する必要がある。シェルのメタ文字 (`; & | \` $ > < 改行 \`) を含む行は無条件で拒否。

```
composer install [--no-dev] [-o|--optimize-autoloader] [--no-interaction]
composer dump-autoload [-o|--optimize]
php artisan migrate --force
php artisan config:cache | route:cache | view:cache | storage:link | optimize | optimize:clear
php bin/console cache:clear
php bin/console doctrine:migrations:migrate --no-interaction
npm ci [--omit=dev]
npm run build
```

連続する空白があるだけでも拒否される（`preg_replace('/\s+/', ' ', $cmd)` と一致すること）。
1 行に 1 コマンドで、`&&` でつなぐことはできない。複数実行したいときは行を分ける。

## vhost 追加ディレクティブ (`app/Domain/VhostRenderer.php`)

行頭のディレクティブ名がこの一覧にあるものだけ通る（`#` で始まる行と空行は無視）。

```
Header, RequestHeader, Redirect, RedirectMatch,
RewriteEngine, RewriteCond, RewriteRule,
ErrorDocument, AddDefaultCharset, Options,
ProxyPassMatch, SetEnvIf, Timeout, LimitRequestBody
```

`<Directory>` などのセクションや `ProxyPass` は通らない。これは意図的な制限で、
vhost の骨格はテンプレート生成に任せる設計になっている。

## 実行環境 (`ServiceRepo::RUNTIMES`)

```
php8.3-fpm, php8.2-fpm, php8.1-fpm, static
```

## git デプロイ

`agent/config.php` の `git_repos` にサービス名で登録されたリポジトリのみ。
未登録のサービスに対する git デプロイは拒否される。URL を web 側から指定することはできない。
