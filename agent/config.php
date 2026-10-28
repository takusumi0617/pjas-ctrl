<?php
// pjas-agentd 設定 (root で読まれる)。web 側 config.php と整合させること。
return [
    'socket'        => '/run/pjas-agent.sock',
    'socket_group'  => 'pjas',      // web ユーザーが所属するグループ
    'socket_mode'   => 0660,

    'www_root'      => '/var/www',
    'upload_dir'    => '/var/lib/pjas/uploads',   // web が置いた検疫前アーカイブ。ここ以外は読まない
    'job_dir'       => '/var/log/pjas/jobs',
    'tls_cert_file' => '/etc/ssl/cloudflare/memo.takusumi.work.pem',
    'tls_key_file'  => '/etc/ssl/cloudflare/memo.takusumi.work.key',
    'cron_dir'      => '/etc/cron.d',
    'cron_script_dir' => '/var/lib/pjas/cron',
    'cron_log_dir'  => '/var/log/pjas/cron',

    'web_user'      => 'www-data',
    'web_group'     => 'www-data',

    'apache_sites'  => '/etc/apache2/sites-available',
    // 有効化済みサイト。同じ ServerName の管理外設定を反映時に無効化するために見る
    'apache_sites_enabled' => '/etc/apache2/sites-enabled',
    'apachectl'     => '/usr/sbin/apachectl',
    'a2ensite'      => '/usr/sbin/a2ensite',
    'a2dissite'     => '/usr/sbin/a2dissite',
    'systemctl'     => '/usr/bin/systemctl',

    // git デプロイを使う場合のみ。サービス名 => リポジトリ URL の固定表。
    // ここに無いサービスは git デプロイを拒否する。
    'git_repos'     => [
        'pjas-memo' => 'https://github.com/takusumi0617/pjas-memo.git',
    ],

    // root 所有の関連付け台帳・実行スクリプト。管理画面からは書き換え不可。
    'daemon_dir' => '/var/lib/pjas-daemons',
    'daemon_unit_dir' => '/etc/systemd/system',

    'hook_timeout'   => 300,
    'health_retries' => 5,
    'health_wait'    => 2,
];
