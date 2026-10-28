<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Agent\AgentClient;
use Pjas\Support\Audit;
use Pjas\Support\Config;

final class Deployer
{
    private const ARCHIVE_RE = '/\.(zip|tar\.gz|tgz)$/i';

    /** agent に渡すサービス仕様 (agent 側で再検証される) */
    public static function spec(array $s): array
    {
        return [
            'name'           => $s['name'],
            'domain'         => $s['domain'],
            'runtime'        => $s['runtime'],
            'docroot'        => $s['docroot'],
            'allow_override' => $s['allow_override'],
            'extra_conf'     => $s['extra_conf'],
            'tls'            => (int) $s['tls'],
            'shared'         => array_values(array_filter(explode(',', (string) $s['shared_paths']))),
            'hooks'          => array_values(array_filter(explode("\n", (string) $s['hooks']))),
            'keep_releases'  => (int) $s['keep_releases'],
            'health_path'    => $s['health_path'],
            'health_status'  => (int) $s['health_status'],
        ];
    }

    /**
     * アップロードされたアーカイブからデプロイを開始する。
     * @return int job id
     */
    public static function fromUpload(array $service, array $file, array $opts, string $actor): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['ファイルのアップロードに失敗しました (エラーコード ' . ($file['error'] ?? '?') . ')']);
        }
        if ($file['size'] > (int) Config::get('max_upload')) {
            throw new ValidationException(['ファイルの大きさが上限を超えています']);
        }
        if (!preg_match(self::ARCHIVE_RE, (string) $file['name'])) {
            throw new ValidationException(['.zip / .tar.gz / .tgz の圧縮ファイルだけアップロードできます']);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException(['アップロードされたファイルを受け取れませんでした']);
        }

        $dir = rtrim((string) Config::get('upload_dir'), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new \RuntimeException("アップロード先を作成できません: {$dir}");
        }
        $ext  = preg_match('/\.zip$/i', $file['name']) ? 'zip' : 'tar.gz';
        $stage = $dir . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $stage)) {
            throw new \RuntimeException('アップロードファイルを保存できません');
        }
        chmod($stage, 0640);

        $sha = hash_file('sha256', $stage);
        $job = JobRepo::create((int) $service['id'], 'deploy', $actor, basename((string) $file['name']));

        try {
            AgentClient::call('deploy.start', [
                'job_id'      => $job,
                'service'     => self::spec($service),
                'source'      => ['type' => 'upload', 'path' => $stage, 'sha256' => $sha,
                                  'name' => basename((string) $file['name'])],
                'label'       => (string) ($opts['label'] ?? ''),
                'auto_rollback' => !empty($opts['auto_rollback']),
                'maintenance'   => !empty($opts['maintenance']),
                'apply_vhost'   => !empty($opts['apply_vhost']),
            ], $actor);
        } catch (\Throwable $e) {
            @unlink($stage);
            \Pjas\Support\Db::run("UPDATE jobs SET state='failed', detail=?, finished_at=datetime('now') WHERE id=?",
                [$e->getMessage(), $job]);
            Audit::log($actor, 'service.deploy', $service['name'], ['job' => $job], 'failed');
            throw $e;
        }

        Audit::log($actor, 'service.deploy', $service['name'], ['job' => $job, 'source' => 'upload', 'sha256' => $sha]);
        return $job;
    }

    /** 登録済みの取得元からブランチ・タグを取得する。HEADは既定ブランチ。 */
    public static function fromGit(array $service, string $ref, array $opts, string $actor): int
    {
        try { $ref = GitHubRepository::ref($ref); }
        catch (\InvalidArgumentException $e) { throw new ValidationException([$e->getMessage()]); }
        $job = JobRepo::create((int) $service['id'], 'deploy', $actor, 'git:' . $ref);
        try {
            AgentClient::call('deploy.start', [
                'job_id' => $job, 'service' => self::spec($service),
                'source' => ['type' => 'git', 'ref' => $ref],
                'label' => (string) ($opts['label'] ?? ''),
                'auto_rollback' => !empty($opts['auto_rollback']),
                'maintenance' => !empty($opts['maintenance']),
                'apply_vhost' => !empty($opts['apply_vhost']),
            ], $actor);
        } catch (\Throwable $e) {
            \Pjas\Support\Db::run("UPDATE jobs SET state='failed', detail=?, finished_at=datetime('now') WHERE id=?", [$e->getMessage(), $job]);
            Audit::log($actor, 'service.deploy', $service['name'], ['job' => $job], 'failed');
            throw $e;
        }
        Audit::log($actor, 'service.deploy', $service['name'], ['job' => $job, 'ref' => $ref]);
        return $job;
    }

    /**
     * デプロイを伴わない取り込み。既に /var/www/<name> にあるファイルを
     * そのままひとつのリリースにして公開状態にする (導入時の移行用)。
     */
    public static function adopt(array $service, string $actor, array $daemons = []): int
    {
        if (ReleaseRepo::current((int) $service['id']) !== null) {
            throw new ValidationException(['すでに公開中のバージョンがあります。取り込みは初回のみ行えます']);
        }
        $job = JobRepo::create((int) $service['id'], 'adopt', $actor, '既存ファイルの取り込み');
        try {
            AgentClient::call('deploy.adopt', [
                'daemons' => $daemons,
                'job_id'  => $job,
                'service' => self::spec($service),
            ], $actor);
        } catch (\Throwable $e) {
            \Pjas\Support\Db::run("UPDATE jobs SET state='failed', detail=?, finished_at=datetime('now') WHERE id=?", [$e->getMessage(), $job]);
            Audit::log($actor, 'service.adopt', $service['name'], ['job' => $job, 'daemons' => $daemons], 'failed');
            throw $e;
        }
        Audit::log($actor, 'service.adopt', $service['name'], ['job' => $job, 'daemons' => $daemons]);
        return $job;
    }

    public static function rollback(array $service, string $tag, string $actor): int
    {
        if (ReleaseRepo::find((int) $service['id'], $tag) === null) {
            throw new ValidationException(['指定されたバージョンが見つかりません']);
        }
        $job = JobRepo::create((int) $service['id'], 'rollback', $actor, $tag);
        AgentClient::call('deploy.rollback', [
            'job_id'  => $job,
            'service' => self::spec($service),
            'tag'     => $tag,
        ], $actor);
        Audit::log($actor, 'service.rollback', $service['name'], ['job' => $job, 'tag' => $tag]);
        return $job;
    }
}
