<?php
declare(strict_types=1);
namespace Pjas\Shared;

/** 静的なHTMLをApacheに直接渡し、PHPやアプリの障害時にも応答できるようにする。 */
final class SiteErrors
{
    public const MESSAGES = [
        400 => ['リクエストを受け付けられません', '送信された内容を確認できませんでした。ホームからもう一度操作してください。'],
        401 => ['ログインが必要です', 'このページを開くには認証が必要です。ログイン情報を確認してください。'],
        403 => ['このページにはアクセスできません', 'アクセスが許可されていないページです。必要な場合はサイトの管理者に確認してください。'],
        404 => ['ページが見つかりません', 'ページが移動したか、削除された可能性があります。URLを確認するか、ホームから探してみてください。'],
        405 => ['この操作は利用できません', 'このページでは指定された操作を受け付けていません。ホームから操作をやり直してください。'],
        408 => ['接続がタイムアウトしました', '通信に時間がかかったため、接続を終了しました。通信環境を確認してもう一度お試しください。'],
        410 => ['このページの公開は終了しました', 'お探しのページは公開を終了しています。ホームからほかのページをご確認ください。'],
        413 => ['送信するデータが大きすぎます', 'ファイルや入力内容のサイズを小さくして、もう一度お試しください。'],
        414 => ['URLが長すぎます', 'URLの長さが上限を超えています。ホームからページを開き直してください。'],
        415 => ['このデータ形式には対応していません', '送信するファイルやデータの形式を確認して、もう一度お試しください。'],
        422 => ['送信内容を確認してください', '入力内容を処理できませんでした。内容を確認して、もう一度お試しください。'],
        429 => ['アクセスが集中しています', '短い時間に多くのアクセスがありました。少し時間をおいてから、もう一度お試しください。'],
        500 => ['処理を続けられませんでした', 'サーバーで問題が発生しました。しばらく待ってからやり直してください。解決しない場合は管理者に連絡してください。'],
        501 => ['この機能には対応していません', '指定された処理をサーバーで実行できませんでした。サイトの管理者に確認してください。'],
        502 => ['サイトに接続できません', 'サイトの処理を行うプログラムから、正常な応答がありませんでした。しばらく待ってからもう一度お試しください。'],
        503 => ['ただいまご利用いただけません', 'メンテナンス中か、アクセスが集中している可能性があります。時間をおいてもう一度お試しください。'],
        504 => ['サイトの応答に時間がかかっています', 'サイトの処理が時間内に完了しませんでした。しばらく待ってからもう一度お試しください。'],
    ];

    public static function html(int $code): string
    {
        [$title, $message] = self::MESSAGES[$code];
        return strtr((string) file_get_contents(__DIR__ . '/site_error.html'), [
            '{{CODE}}' => (string) $code, '{{TITLE}}' => $title, '{{MESSAGE}}' => $message,
        ]);
    }

    public static function directives(): string
    {
        $out = "    # pjas-ctrl standard error pages v1 (site overrides take priority)\n";
        foreach (self::MESSAGES as $code => $_) {
            $html = str_replace(["\r", "\n"], '', self::html($code));
            // Apacheの直接応答はISO-8859-1になるため、日本語を文字参照で渡す。
            $html = preg_replace_callback('/[^\x00-\x7f]/u', static function ($m) {
                $bytes = array_values(unpack('C*', $m[0]));
                $point = $bytes[0] & (0x7f >> count($bytes));
                foreach (array_slice($bytes, 1) as $byte) $point = ($point << 6) | ($byte & 0x3f);
                return '&#' . $point . ';';
            }, $html);
            $html = str_replace(['\\', '"'], ['\\\\', '\\"'], $html);
            $out .= '    ErrorDocument ' . $code . ' "' . $html . '"' . "\n";
        }
        return $out . "    # end pjas-ctrl standard error pages\n";
    }

    public static function decorate(string $conf): string
    {
        // 旧版を含め、ctrlが生成した部分だけを置き換える。
        $header = '[\t ]*# pjas-ctrl standard error pages v1 \(site overrides take priority\)';
        $line = '[\t ]*ErrorDocument [0-9]+ "<!doctype html>[^\r\n]*"[\t ]*(?:\r?\n|$)';
        $pattern = '~^' . $header . '\r?\n(?:' . $line . '){' . count(self::MESSAGES) . '}(?:[\t ]*# end pjas-ctrl standard error pages(?:\r?\n|$))?~m';
        $conf = preg_replace($pattern, '', $conf);
        if (preg_match('~^' . $header . '~m', $conf)) {
            throw new \InvalidArgumentException('共通エラーページの生成部分が編集されています。公開設定を確認してください');
        }
        // VirtualHostの冒頭に既定値を追加する。後続の独自指定やDirectoryの指定を優先。
        return preg_replace_callback('~(^[\t ]*<VirtualHost\s+[^>]+>[^\S\r\n]*\R)~mi',
            static fn($m) => $m[1] . self::directives(), $conf);
    }
}
