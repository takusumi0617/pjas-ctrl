<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Support\Db;

final class ReleaseRepo
{
    /** @return array<int,array<string,mixed>> */
    public static function forService(int $serviceId, int $limit = 30): array
    {
        return Db::all(
            'SELECT * FROM releases WHERE service_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$serviceId]
        );
    }

    public static function current(int $serviceId): ?array
    {
        return Db::one("SELECT * FROM releases WHERE service_id = ? AND state = 'current'", [$serviceId]);
    }

    public static function find(int $serviceId, string $tag): ?array
    {
        return Db::one('SELECT * FROM releases WHERE service_id = ? AND tag = ?', [$serviceId, $tag]);
    }

    /**
     * agent が報告したリリース情報を反映する。
     * $rel = [tag, source, sha256, size, state(current|failed), label]
     */
    public static function syncFromAgent(int $serviceId, array $rel, string $actor): void
    {
        $tag = (string) ($rel['tag'] ?? '');
        if ($tag === '') {
            return;
        }
        $state = (string) ($rel['state'] ?? 'archived');

        if (self::find($serviceId, $tag) === null) {
            Db::insert(
                'INSERT INTO releases (service_id, tag, label, source, sha256, size, state, deployed_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$serviceId, $tag, (string) ($rel['label'] ?? ''), (string) ($rel['source'] ?? ''),
                 (string) ($rel['sha256'] ?? ''), (int) ($rel['size'] ?? 0), $state, $actor]
            );
        } else {
            Db::run('UPDATE releases SET state = ? WHERE service_id = ? AND tag = ?', [$state, $serviceId, $tag]);
        }

        if ($state === 'current') {
            Db::run(
                "UPDATE releases SET state = 'archived' WHERE service_id = ? AND tag <> ? AND state = 'current'",
                [$serviceId, $tag]
            );
        }
        // agent が掃除したリリースを DB からも消す
        if (!empty($rel['pruned']) && is_array($rel['pruned'])) {
            foreach ($rel['pruned'] as $old) {
                Db::run('DELETE FROM releases WHERE service_id = ? AND tag = ?', [$serviceId, (string) $old]);
            }
        }
    }
}
