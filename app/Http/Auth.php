<?php
declare(strict_types=1);

namespace Pjas\Http;

use Pjas\Support\Audit;
use Pjas\Support\Db;

final class Auth
{
    private const RANK = ['viewer' => 1, 'operator' => 2, 'owner' => 3];

    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) {
            return null;
        }
        return Db::one('SELECT id, email, role FROM users WHERE id = ?', [$_SESSION['uid']]);
    }

    public static function actor(): string
    {
        return self::user()['email'] ?? 'anonymous';
    }

    public static function attempt(string $email, string $password): bool
    {
        $u = Db::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Audit::log($email, 'auth.login', '', [], 'failed');
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        Audit::log($email, 'auth.login');
        return true;
    }

    public static function logout(): void
    {
        Audit::log(self::actor(), 'auth.logout');
        $_SESSION = [];
        session_destroy();
    }

    public static function can(string $role): bool
    {
        $u = self::user();
        return $u !== null && (self::RANK[$u['role']] ?? 0) >= (self::RANK[$role] ?? 99);
    }

    public static function require(string $role): void
    {
        if (self::user() === null) {
            header('Location: /login');
            exit;
        }
        if (!self::can($role)) {
            View::error(403, 'この操作には「' . \Pjas\Support\Labels::role($role) . '」権限が必要です。管理者に確認してください。');
            exit;
        }
    }
}
