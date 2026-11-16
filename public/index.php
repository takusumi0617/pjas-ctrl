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
$components=new \Pjas\Http\Controllers\ComponentController();
$r->get('/components',[$components,'index'],'owner');
$r->post('/components',[$components,'action'],'owner');

$auth = new AuthController();
$r->get('/login', [$auth, 'showLogin'], 'public');
$r->add('POST', '/login', [$auth, 'login'], 'public');
$r->post('/logout', [$auth, 'logout'], 'viewer');
$r->get('/login/verify',[$auth,'showVerify'],'public');
$r->post('/login/verify',[$auth,'verify'],'public');
$security=new \Pjas\Http\Controllers\SecurityController();
$r->get('/security',[$security,'index']);$r->post('/security',[$security,'action'],'viewer');
$users=new \Pjas\Http\Controllers\UsersController();
$r->get('/users',[$users,'index'],'owner');$r->post('/users',[$users,'save'],'owner');
$ops=new \Pjas\Http\Controllers\OperationsController();
$r->get('/operations',[$ops,'index']);
$server=new \Pjas\Http\Controllers\ServerController();
$r->get('/server',[$server,'index'],'owner');
$r->post('/server',[$server,'action'],'owner');
$r->get('/connections',[$ops,'connections'],'owner');$r->post('/connections',[$ops,'saveConnection'],'owner');
$r->post('/connections/github/start',[$ops,'githubStart'],'owner');$r->get('/connections/github/callback',[$ops,'githubCallback'],'owner');
$r->get('/services/{name}/operations',[$ops,'site'],'operator');$r->post('/services/{name}/operations',[$ops,'action'],'operator');
$r->add('POST','/hooks/github',[(new \Pjas\Http\Controllers\WebhookController()),'receive'],'webhook');

$r->get('/', [new DashboardController(), 'index']);

$lifecycle=new \Pjas\Http\Controllers\SiteLifecycleController();
$r->get('/retired-sites',[$lifecycle,'index'],'owner');
$r->get('/services/{name}/delete',[$lifecycle,'form'],'owner');
$r->post('/services/{name}/delete',[$lifecycle,'action'],'owner');

$svc = new ServiceController();
$r->get('/services', [$svc, 'index']);
$r->get('/services/new', [$svc, 'newForm'], 'owner');
$r->get('/services/import', [$svc, 'importForm'], 'owner');
$r->post('/services/import', [$svc, 'import'], 'owner');
$r->post('/services/github-preview', [$svc, 'githubPreview'], 'owner');
$r->post('/services', [$svc, 'create'], 'owner');
$r->get('/services/{name}', [$svc, 'show']);
$r->post('/services/{name}/settings', [$svc, 'updateSettings'], 'owner');
$r->post('/services/{name}/isolation', [$svc, 'isolation'], 'owner');
$r->post('/services/{name}/health', [$svc, 'health'], 'viewer');

$dep = new DeployController();
$r->get('/deploy', [$dep, 'form'], 'operator');
$r->get('/rollback', [$dep, 'rollbackForm'], 'operator');
$r->post('/deploy', [$dep, 'deploy'], 'operator');
$r->post('/deploy/review',[$dep,'review'],'operator');$r->get('/deploy/review',[$dep,'showReview'],'operator');
$r->post('/deploy/confirm',[$dep,'confirm'],'operator');$r->get('/services/{name}/review-file',[$dep,'reviewFile'],'operator');
$r->post('/services/{name}/rollback', [$dep, 'rollback'], 'operator');
$r->post('/services/{name}/adopt', [$dep, 'adopt'], 'operator');

$vh = new VhostController();
$r->post('/services/{name}/pause', [$vh, 'pause'], 'owner');
$r->post('/services/{name}/vhost', [$vh, 'apply'], 'owner');

$job = new JobController();
$r->get('/jobs/{id}', [$job, 'show']);
$r->get('/jobs/{id}/stream', [$job, 'stream']);

$r->get('/audit', [new AuditController(), 'index'], 'operator');

$daemon = new \Pjas\Http\Controllers\DaemonController();
$r->get('/daemons', [$daemon, 'index'], 'owner');
$r->post('/daemons/save', [$daemon, 'save'], 'owner');
$r->post('/daemons/action', [$daemon, 'action'], 'owner');

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
    View::error(500);
}
