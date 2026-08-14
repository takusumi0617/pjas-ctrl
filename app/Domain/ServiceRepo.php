<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Support\Db;

final class ServiceRepo
{
    public const NAME_RE   = '/^[a-z0-9][a-z0-9-]{0,31}$/';
    public const DOMAIN_RE = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';
    public const RUNTIMES  = ['php8.3-fpm', 'php8.2-fpm', 'php8.1-fpm', 'static'];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::all('SELECT * FROM services ORDER BY name');
    }

    public static function find(string $name): ?array
    {
        return Db::one('SELECT * FROM services WHERE name = ?', [$name]);
    }

    public static function findOrFail(string $name): array
    {
        $s = self::find($name);
        if ($s === null) {
            throw new \RuntimeException("サービスがありません: {$name}");
        }
        return $s;
    }

    /** 入力を検証して正規化する。不正なら例外。 */
    public static function validate(array $in, bool $isNew): array
    {
        $err = [];
        $out = [];

        $out['name'] = trim((string) ($in['name'] ?? ''));
        if (!preg_match(self::NAME_RE, $out['name'])) {
            $err[] = 'サービス名は英小文字・数字・ハイフン (32文字以内、先頭は英数字) です';
        }
        if ($isNew && self::find($out['name']) !== null) {
            $err[] = "サービス名 {$out['name']} は既に存在します";
        }

        $out['domain'] = strtolower(trim((string) ($in['domain'] ?? '')));
        if (!preg_match(self::DOMAIN_RE, $out['domain'])) {
            $err[] = 'ドメイン名の形式が不正です';
        }

        $out['runtime'] = (string) ($in['runtime'] ?? 'php8.3-fpm');
        if (!in_array($out['runtime'], self::RUNTIMES, true)) {
            $err[] = 'ランタイムが不正です';
        }

        // docroot はリリース内の相対パスのみ。.. や絶対パスを拒否。
        $out['docroot'] = trim((string) ($in['docroot'] ?? 'public'), '/');
        if ($out['docroot'] !== '' && !preg_match('#^[A-Za-z0-9._/-]+$#', $out['docroot'])) {
            $err[] = 'DocumentRoot に使えない文字が含まれています';
        }
        if (str_contains($out['docroot'], '..')) {
            $err[] = 'DocumentRoot に .. は使えません';
        }

        $out['health_path']    = '/' . ltrim((string) ($in['health_path'] ?? '/healthz'), '/');
        $out['health_status']  = max(100, min(599, (int) ($in['health_status'] ?? 200)));
        $out['keep_releases']  = max(1, min(50, (int) ($in['keep_releases'] ?? 5)));
        $out['allow_override'] = in_array($in['allow_override'] ?? 'None', ['None', 'FileInfo', 'All'], true)
            ? (string) $in['allow_override'] : 'None';
        $out['tls']            = !empty($in['tls']) ? 1 : 0;

        $extra = (string) ($in['extra_conf'] ?? '');
        foreach (VhostRenderer::rejectedDirectives($extra) as $bad) {
            $err[] = "追加ディレクティブに使えない指定があります: {$bad}";
        }
        $out['extra_conf'] = $extra;

        $shared = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($in['shared_paths'] ?? ''))));
        foreach ($shared as $p) {
            if (str_starts_with($p, '/') || str_contains($p, '..')) {
                $err[] = "shared パスが不正です: {$p}";
            }
        }
        $out['shared_paths'] = implode(',', $shared);

        $hooks = array_filter(array_map('trim', explode("\n", (string) ($in['hooks'] ?? ''))));
        foreach ($hooks as $h) {
            if (!Hooks::isAllowed($h)) {
                $err[] = "許可されていないフックコマンドです: {$h}";
            }
        }
        $out['hooks'] = implode("\n", $hooks);

        if ($err) {
            throw new ValidationException($err);
        }
        return $out;
    }

    public static function create(array $v): int
    {
        return Db::insert(
            'INSERT INTO services (name, domain, runtime, docroot, health_path, health_status, keep_releases,
                                   allow_override, extra_conf, tls, shared_paths, hooks)
             VALUES (:name,:domain,:runtime,:docroot,:health_path,:health_status,:keep_releases,
                     :allow_override,:extra_conf,:tls,:shared_paths,:hooks)',
            $v
        );
    }

    public static function update(string $name, array $v): void
    {
        unset($v['name']);               // 名前 (= ディレクトリ) の変更は許可しない
        $v['orig'] = $name;
        Db::run(
            'UPDATE services SET domain=:domain, runtime=:runtime, docroot=:docroot, health_path=:health_path,
                    health_status=:health_status, keep_releases=:keep_releases, allow_override=:allow_override,
                    extra_conf=:extra_conf, tls=:tls, shared_paths=:shared_paths, hooks=:hooks
             WHERE name=:orig',
            $v
        );
    }

    public static function setState(int $id, string $state): void
    {
        Db::run("UPDATE services SET state = ?, checked_at = datetime('now') WHERE id = ?", [$state, $id]);
    }
}
