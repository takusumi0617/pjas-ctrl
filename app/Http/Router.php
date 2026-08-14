<?php
declare(strict_types=1);

namespace Pjas\Http;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable,3:?string}> */
    private array $routes = [];

    /** $role: 必要ロール (null = 認証のみ) */
    public function add(string $method, string $pattern, callable $handler, ?string $role = 'viewer'): void
    {
        $this->routes[] = [$method, $pattern, $handler, $role];
    }

    public function get(string $p, callable $h, ?string $role = 'viewer'): void   { $this->add('GET', $p, $h, $role); }
    public function post(string $p, callable $h, ?string $role = 'operator'): void { $this->add('POST', $p, $h, $role); }

    /** 公開ルート (ログイン画面など) は $role = false 相当の 'public' */
    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as [$m, $pattern, $handler, $role]) {
            if ($m !== $method) {
                continue;
            }
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $mt)) {
                continue;
            }
            $args = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($method === 'POST') {
                Csrf::check();
            }
            if ($role !== 'public') {
                Auth::require($role ?? 'viewer');
            }
            $handler($args);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => '404', 'message' => 'ページが見つかりません']);
    }
}
