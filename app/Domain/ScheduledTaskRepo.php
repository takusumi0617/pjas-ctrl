<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Support\Db;

final class ScheduledTaskRepo
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::all(
            'SELECT t.*, s.name AS service FROM scheduled_tasks t
             JOIN services s ON s.id = t.service_id WHERE s.id NOT IN (SELECT service_id FROM service_lifecycle WHERE state<>\'active\') ORDER BY s.name, t.id'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT t.*, s.name AS service FROM scheduled_tasks t
             JOIN services s ON s.id = t.service_id WHERE t.id = ?',
            [$id]
        );
    }

    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = '表示名は1〜80文字で入力してください';
        }

        $schedule = trim((string) ($in['schedule'] ?? ''));
        if (!self::validSchedule($schedule)) {
            $errors[] = '実行時刻は「分 時 日 月 曜日」の5項目で入力してください。例: */5 * * * *';
        }

        $command = trim((string) ($in['command'] ?? ''));
        if ($command === '' || strlen($command) > 4000 || str_contains($command, "\0")) {
            $errors[] = '実行内容は1〜4000バイトで入力してください';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'schedule' => $schedule, 'command' => $command,
                'enabled' => !empty($in['enabled']) ? 1 : 0];
    }

    public static function validSchedule(string $schedule): bool
    {
        if (in_array($schedule, ['@hourly', '@daily', '@weekly', '@monthly', '@yearly'], true)) {
            return true;
        }
        $fields = preg_split('/\s+/', trim($schedule)) ?: [];
        if (count($fields) !== 5) {
            return false;
        }
        foreach ($fields as $field) {
            if (!preg_match('/^[0-9A-Za-z*\/,\-]+$/', $field)) {
                return false;
            }
        }
        return true;
    }

    public static function create(int $serviceId, array $v, string $actor): int
    {
        return Db::insert(
            'INSERT INTO scheduled_tasks (service_id,name,schedule,command,enabled,created_by) VALUES (?,?,?,?,?,?)',
            [$serviceId, $v['name'], $v['schedule'], $v['command'], $v['enabled'], $actor]
        );
    }

    public static function update(int $id, array $v): void
    {
        Db::run(
            "UPDATE scheduled_tasks SET name=?, schedule=?, command=?, enabled=?, updated_at=datetime('now') WHERE id=?",
            [$v['name'], $v['schedule'], $v['command'], $v['enabled'], $id]
        );
    }

    public static function delete(int $id): void
    {
        Db::run('DELETE FROM scheduled_tasks WHERE id = ?', [$id]);
    }
}
