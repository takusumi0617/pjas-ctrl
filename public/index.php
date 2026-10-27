<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Pjas\Http\Controllers\AuditController;
use Pjas\Http\Controllers\AuthController;
use Pjas\Http\Controllers\DashboardController;
use Pjas\Http\Controllers\DeployController;
use Pjas\Http\Controllers\JobController;
use Pjas\Http\Controllers\ServiceController;
use Pjas\Http\Controllers\ScheduledTaskController;
use Pjas\Http\Controllers\VhostController;
use Pjas\Http\Router;
use Pjas\Http\View;

$r = new Router();

$auth = new AuthController();
$r->get('/login', [$auth, 'showLogin'], 'public');
$r->add('POST', '/login', [$auth, 'login'], 'public');
$r->post('/logout', [$auth, 'logout'], 'viewer');

$r->get('/', [new DashboardController(), 'index']);

$svc = new ServiceController();
$r->get('/services', [$svc, 'index']);
$r->get('/services/new', [$svc, 'newForm'], 'owner');
$r->get('/services/import', [$svc, 'importForm'], 'owner');
$r->post('/services/import', [$svc, 'import'], 'owner');
$r->post('/services', [$svc, 'create'], 'owner');
$r->get('/services/{name}', [$svc, 'show']);
$r->post('/services/{name}/settings', [$svc, 'updateSettings'], 'owner');
$r->post('/services/{name}/health', [$svc, 'health'], 'viewer');

$dep = new DeployController();
$r->get('/deploy', [$dep, 'form'], 'operator');
$r->post('/deploy', [$dep, 'deploy'], 'operator');
$r->post('/services/{name}/rollback', [$dep, 'rollback'], 'operator');
$r->post('/services/{name}/adopt', [$dep, 'adopt'], 'operator');

$vh = new VhostController();
$r->post('/services/{name}/vhost', [$vh, 'apply'], 'owner');

$job = new JobController();
$r->get('/jobs/{id}', [$job, 'show']);
$r->get('/jobs/{id}/stream', [$job, 'stream']);

$r->get('/audit', [new AuditController(), 'index'], 'operator');

$scheduled = new ScheduledTaskController();
$r->get('/scheduled-tasks', [$scheduled, 'index'], 'owner');
$r->post('/scheduled-tasks', [$scheduled, 'create'], 'owner');
$r->post('/scheduled-tasks/{id}', [$scheduled, 'update'], 'owner');
$r->post('/scheduled-tasks/{id}/delete', [$scheduled, 'delete'], 'owner');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

try {
    $r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $e) {
    error_log('[pjas-ctrl] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    View::render('error', ['title' => 'エラー', 'message' => $e->getMessage()]);
}
