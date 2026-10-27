<?php
declare(strict_types=1);

namespace Pjas\Support;

/**
 * 画面表示用の日本語ラベル。
 *
 * DB に入る値 (healthy / deploy / php8.3-fpm など) はそのままに、
 * 画面では初めて使う人にも意味が分かる語を出すための対応表。
 */
final class Labels
{
    /** サービスの状態 */
    public static function state(string $v): string
    {
        return match ($v) {
            'healthy'   => '正常',
            'unhealthy' => '異常',
            default     => '未確認',
        };
    }

    /** ジョブ (実行中の作業) の結果 */
    public static function jobState(string $v): string
    {
        return match ($v) {
            'succeeded' => '成功',
            'failed'    => '失敗',
            'running'   => '実行中',
            default     => '待機中',
        };
    }

    /** ジョブの種別 */
    public static function jobType(string $v): string
    {
        return match ($v) {
            'deploy'   => '公開',
            'rollback' => '前のバージョンに戻す',
            'adopt'    => '既存ファイルの取り込み',
            'vhost'    => '公開設定の反映',
            'tls'      => '証明書の取得・更新',
            'reload'   => 'サーバー再読み込み',
            default    => $v,
        };
    }

    /** リリース (公開されたバージョン) の状態 */
    public static function releaseState(string $v): string
    {
        return match ($v) {
            'current' => '公開中',
            'failed'  => '失敗',
            'pending' => '準備中',
            default   => '保管中',
        };
    }

    /** 実行環境 */
    public static function runtime(string $v): string
    {
        return $v === 'static'
            ? 'HTML・画像だけのサイト'
            : 'PHP を使うサイト（PHP ' . str_replace(['php', '-fpm'], '', $v) . '）';
    }

    /** 利用者の権限 */
    public static function role(string $v): string
    {
        return match ($v) {
            'owner'    => 'すべて管理できる',
            'operator' => '公開作業ができる',
            'viewer'   => '見るだけ',
            default    => $v,
        };
    }

    /** 監査ログの操作名 */
    public static function action(string $v): string
    {
        return match ($v) {
            'auth.login'         => 'ログイン',
            'auth.logout'        => 'ログアウト',
            'service.create'     => 'サイトの登録',
            'service.update'     => 'サイト設定の変更',
            'service.deploy'     => '新しいバージョンを公開',
            'service.rollback'   => '前のバージョンに戻す',
            'service.adopt'      => '既存ファイルの取り込み',
            'service.health'     => '動作確認',
            'apache.vhost.apply' => '公開設定の反映',
            'tls.issue'          => '証明書の取得・更新',
            default              => $v,
        };
    }

    /** ファイル一覧の種別 */
    public static function entryType(string $v): string
    {
        return match ($v) {
            'dir'  => 'フォルダ',
            'link' => 'ショートカット',
            default => 'ファイル',
        };
    }
}
