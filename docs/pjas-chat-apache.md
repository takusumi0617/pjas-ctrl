# pjas-chat の公開設定

> この構成例は独自Apache設定を継承する従来サイト向けです。新規の既定隔離サイトはこのAPIプロキシ設定を直接適用できません。v2での管理者レビューか、標準DocumentRootで配信できる構成へ変更してください。
[ドキュメント一覧](README.md) / [サイト運用](sites.md)

サイトの「設定」で「公開するフォルダ」を `dist`、PHP を `php8.5-fpm` に設定します。ビルド済みの `dist/index.html`、`api/index.php`、必要な vendor がリリース内にあることを確認してください。公開フォルダをリポジトリ全体にすると設定やDBを公開してしまうため、必ず dist を指定します。

「Apache 設定に追加する行」に、次を指定します。`{{document_root}}` は公開フォルダ、`{{site_root}}` はサイトの current フォルダへ置き換わります。リリース日時を直接書く必要はありません。

```apache
<Directory {{document_root}}>
    Require all granted
    FallbackResource /index.html
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' wss:; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"
</Directory>
ProxyPass /ws ws://127.0.0.1:8081
ProxyPassReverse /ws ws://127.0.0.1:8081
ProxyPassMatch ^/api(?:/.*)?$ unix:/run/php/php8.5-fpm.sock|fcgi://localhost{{site_root}}/api/index.php
```

Apache モジュールを有効化してから「公開設定」で差分を確認し、反映します。

```bash
sudo a2enmod proxy proxy_fcgi proxy_wstunnel rewrite headers ssl
sudo apachectl configtest
sudo systemctl reload apache2
```

証明書は共通の Cloudflare Origin CA 証明書を使用します。HTTPからHTTPSへの転送・VirtualHost・DocumentRoot・PHPの通常の実行設定は管理機能が生成するため、追加欄には記載しません。Directory ブロックは `{{document_root}}` のみ使用でき、VirtualHost、Include、任意パスの Directory は許可しません。

WebSocket は別途「常駐プログラム」で既存の pjas-websocket.service を関連付けるか、新規プログラムとして `exec /usr/bin/php8.5 websocket/server.php start` を登録して起動します。待受ポートが8081の場合の例です。既存のプロセスと二重起動しないでください。

Cloudflare Access、FPM の環境変数（CF_ACCESS_TEAM / CF_ACCESS_AUD）、DBの永続化と権限は pjas-chat の手順に従って別途設定してください。この設定はApacheの転送と配信を管理するもので、アプリの認証設定を自動変更しません。
