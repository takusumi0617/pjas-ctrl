<?php
declare(strict_types=1);

namespace Pjas\Http;

final class View
{
    public static function render(string $view, array $data = [], bool $bare = false): void
    {
        $file = dirname(__DIR__, 2) . '/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("view がありません: {$view}");
        }
        extract($data, EXTR_SKIP);
        if ($bare) {
            require $file;
            return;
        }
        ob_start();
        require $file;
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/views/layout.php';
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public static function redirect(string $to, ?string $flash = null, string $kind = 'ok'): never
    {
        if ($flash !== null) {
            $_SESSION['flash'] = ['kind' => $kind, 'text' => $flash];
        }
        header('Location: ' . $to);
        exit;
    }

    public static function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }
}
