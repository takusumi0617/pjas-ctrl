<?php
declare(strict_types=1);
namespace Pjas\Agentd;
require_once dirname(__DIR__,2).'/app/Domain/ComponentManifest.php';
use Pjas\Domain\ComponentManifest as Schema;

/** Administrator-owned grants. This catalog cannot be modified over RPC. */
final class ComponentPolicy
{
    public array $data;
    public function __construct(private array $cfg)
    {
        $path = $cfg['component_catalog'] ?? '/etc/pjas/components.json';
        self::safe($path, false);
        if (filesize($path) > 1048576) throw new OpException('権限台帳が大きすぎます');
        $this->data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($this->data['version'] ?? null) !== 1) throw new OpException('権限台帳のversionが不正です');
    }
    /** Reject symlink ancestors and mutable administrative configuration. */
    public static function safe(string $path, bool $writable = false): void
    {
        if (!preg_match('~\A/[a-zA-Z0-9_./-]+\z~D', $path) || str_contains($path, '..') || str_contains($path, '//')) throw new OpException('保護パスが不正です');
        $part = '';
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $part .= '/'.$segment; $s = @lstat($part);
            if (!$s || is_link($part)) throw new OpException('保護パスが未登録またはリンクです');
            // Sticky /tmp is permitted as an ancestor for isolated test fixtures.
            $sticky = is_dir($part) && ($s['mode'] & 01000);
            if (!$sticky && (($s['mode'] & 0022) || (!in_array($s['uid'], [0,posix_geteuid()], true) && (!$writable || $part !== $path)))) throw new OpException('保護パスの所有者・権限が不正です');
        }
    }
    public function grant(string $kind, string $id, string $site): array
    {
        Schema::id($id); Spec::name($site);
        $row = $this->data[$kind][$id] ?? null;
        if (!is_array($row) || !in_array($site, $row['sites'] ?? [], true)) throw new OpException('このサイトに許可されていない参照です: '.$kind.'/'.$id);
        return $row;
    }
    public function profile(string $id, string $site): array
    {
        $p = $this->grant('profiles', $id, $site);
        foreach (['user','group'] as $key) if (!preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $p[$key] ?? '')) throw new OpException('実行ユーザー設定が不正です');
        $u = posix_getpwnam($p['user']); $g = posix_getgrnam($p['group']);
        $forbidden = ['root','www-data','pjas',$this->cfg['socket_group']??'pjas',$this->cfg['web_group']??'www-data',$this->cfg['web_user'] ?? 'www-data','pjas-control',$this->cfg['preview_user'] ?? 'pjas-preview'];
        if ($p['user']!==$p['group'] || !$u || !$g || $u['uid'] === 0 || $g['gid'] === 0 || in_array($p['user'],$forbidden,true) || in_array($p['group'],$forbidden,true) || $g['gid'] !== $u['gid']) throw new OpException('専用の非特権ユーザー・プライマリグループが必要です');
        if (!in_array($p['type'] ?? null, ['php','worker'],true)) throw new OpException('プロファイル種別が不正です');
        // A UID/GID is exclusive to a site; group sharing defeats storage isolation.
        foreach ($this->data['profiles'] ?? [] as $otherId=>$other) {
            $otherU = posix_getpwnam($other['user'] ?? ''); $otherG = posix_getgrnam($other['group'] ?? '');
            if ($otherId !== $id && (($otherU && $otherU['uid'] === $u['uid']) || ($otherG && $otherG['gid'] === $g['gid']))) throw new OpException('プロファイル間のUID/GID共有は禁止です');
        }
        if (($p['sites'] ?? []) !== [$site]) throw new OpException('実行プロファイルは1サイト専用にしてください');
        foreach ($forbidden as $name) {
            $f=posix_getpwnam($name); $fg=posix_getgrnam($name);
            if (($f && $f['uid']===$u['uid']) || ($fg && $fg['gid']===$g['gid'])) throw new OpException('共通ユーザーとUID/GIDが重複しています');
        }
        $ids=Proc::run(['/usr/bin/id','-G',$p['user']],null,10);
        $groups=array_unique(preg_split('/\s+/',trim($ids['out'])));
        if ($ids['code']!==0 || $groups!==[(string)$g['gid']]) throw new OpException('専用ユーザーに補助グループを付与しないでください');
        $p['uid'] = $u['uid']; $p['gid'] = $g['gid'];
        return $p;
    }
    public function storage(string $id, string $site, string $profile): array
    {
        $r = $this->grant('storage', $id, $site);
        if (($r['profile'] ?? '') !== $profile) throw new OpException('永続領域の所有権プロファイルが一致しません');
        $path = $r['path'] ?? ''; self::safe($path, true);
        if (!is_dir($path)) throw new OpException('永続領域を管理者が事前作成してください');
        $p = $this->profile($profile, $site); $s = stat($path);
        if ($s['uid'] !== $p['uid'] || $s['gid'] !== $p['gid'] || ($s['mode'] & 0777) !== 0700) throw new OpException('永続領域は専用所有者・0700にしてください');
        $www = rtrim($this->cfg['www_root'],'/');
        if ($path === $www || str_starts_with($path, $www.'/')) throw new OpException('保護領域は公開ルート外に配置してください');
        foreach ($this->data['storage'] ?? [] as $otherId=>$o) {
            $q = rtrim($o['path'] ?? '', '/');
            $otherStat=$q!==''?@stat($q):false;
            if ($otherId!==$id && $otherStat && $otherStat['dev']===$s['dev'] && $otherStat['ino']===$s['ino']) throw new OpException('永続領域が同じ実体を指しています');
            if ($otherId !== $id && ($q === $path || str_starts_with($q.'/', $path.'/') || str_starts_with($path.'/', $q.'/'))) throw new OpException('永続領域が重複しています');
        }
        if (($r['sites'] ?? []) !== [$site]) throw new OpException('永続領域は1サイト専用にしてください');
        return $r;
    }
    public function secret(string $id, string $site, string $profile, bool $checkFile=true): array
    {
        $r = $this->grant('secrets', $id, $site);
        if (($r['profile'] ?? '') !== $profile) throw new OpException('秘密情報の利用対象が一致しません');
        if (!in_array($r['purpose']??null,['api','config'],true)) throw new OpException('CA鍵は専用永続領域に配置してください');
        $path=$r['path']??'';
        if (!preg_match('~\A/[a-zA-Z0-9_./-]+\z~D',$path) || str_contains($path,'..')) throw new OpException('秘密情報の保護パスが不正です');
        if (!file_exists($path) && !is_link($path)) {
            if ($checkFile) throw new OpException('秘密情報の保護ファイルが未配置です');
            return $r+['pending'=>true];
        }
        self::safe($path); $s = stat($path);
        if (!is_file($r['path']) || filesize($r['path'])>1048576 || ($s['mode'] & 0077) || $s['uid'] !== posix_geteuid()) throw new OpException('秘密情報はagent所有・0600の通常ファイルにしてください');
        if (!in_array($r['purpose'] ?? null, ['api','config'],true)) throw new OpException('CA鍵は秘密情報として配布せず専用永続領域に配置してください');
        return $r;
    }
    public static function reservedUnit(array $cfg,string $unit): bool
    {
        if (str_starts_with($unit,'pjas-component-')) return true;
        $file=$cfg['component_catalog']??'/etc/pjas/components.json';
        if (!is_file($file)) return false;
        $catalog=new self($cfg);
        foreach ($catalog->data['existing_services']??[] as $row) if (($row['unit']??'')===$unit) return true;
        return false;
    }
    public static function quote(string $s): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/',$s)) throw new OpException('設定に制御文字は使えません');
        return '"'.str_replace(['\\','"','%','$'],['\\\\','\\"','%%','$$'],$s).'"';
    }
}
